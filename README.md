# PBB Hotline

Hotline Beta is the PBB emergency-call and operator-dispatch surface. The current implementation is centered on realtime caller/operator discovery, operator incident handling, media capture/finalization, live caller location, a MapLibre dashboard map, and helper-library UI components.

## Local Setup

### Media Binaries

Hotline resolves media tools in this order:
- `HOTLINE_FFMPEG_BINARY` / `HOTLINE_FFPROBE_BINARY`
- repo-local binaries in `bin/ffmpeg/`
- system `ffmpeg` / `ffprobe` on `PATH`

The intended local Windows setup is:
- `C:\wamp64\www\pbb\hotline\bin\ffmpeg\ffmpeg.exe`
- `C:\wamp64\www\pbb\hotline\bin\ffmpeg\ffprobe.exe`

Linux repo-local paths:
- `bin/ffmpeg/ffmpeg`
- `bin/ffmpeg/ffprobe`

Recommended Linux install:
```bash
sudo apt-get install ffmpeg
```

Recommended explicit override:
```bash
HOTLINE_FFMPEG_BINARY=/opt/ffmpeg/bin/ffmpeg
HOTLINE_FFPROBE_BINARY=/opt/ffmpeg/bin/ffprobe
```

### Build

```bash
npm run build
```

Use WAMP PHP 8.2 for local PHP checks on this workstation:
```powershell
& "C:\wamp64\bin\php\php8.2.29\php.exe" -l app\Support\Sessions\AvailabilityService.php
```

### Session Policy

Hotline uses Laravel web sessions for all surfaces, but caller sessions are intentionally longer-lived because the caller side is an emergency entry point.

Environment settings:
- `SESSION_LIFETIME`: normal operator/admin/command session lifetime, in minutes. Local default is `15`.
- `HOTLINE_CALLER_SESSION_LIFETIME`: caller session lifetime, in minutes. Default is `43200` minutes, or 30 days.

Caller session behavior:
- caller login uses Laravel remember-login
- caller web/API routes use `ConfigureCallerSessionLifetime` to apply the longer caller lifetime
- caller keepalive requests call `/api/session/ping?surface=caller`
- the frontend allows caller keepalive attempts without requiring the tab to be focused
- the caller frontend clamps its local session-expiry clock to at least `43200` minutes so an old short bootstrap value does not redirect an emergency caller on tab focus

Do not raise `SESSION_LIFETIME` just to help callers stay signed in; use `HOTLINE_CALLER_SESSION_LIFETIME` so operator/admin sessions can remain shorter.

## Realtime Model

Hotline uses the shared realtime socket for discovery, presence, call flow, media notifications, and caller location.

Primary rooms:
- `presence.global.hotline`: caller/operator discovery, operator availability presence, realtime connection health, and caller location updates.
- `hotline.media.incident.{incidentId}`: incident media processing and availability events.
- call/session-specific signaling rooms: WebRTC call signaling and reconnect flow.

Important event families:
- `caller.operator.available.request`: caller asks online available operators to respond.
- `caller.operator.available.response`: an available operator responds to discovery.
- `caller.call.request`: caller sends the selected operator a call request.
- `caller.location.updated`: caller location update published during an active call.
- `media.processing` / `media.available`: media lifecycle updates after server-side processing/finalization.
- `presence.state.event`: realtime roster changes used by transfer targeting and operator availability.

Operator availability rules:
- An operator with an active workbench is treated as busy and should not receive new calls.
- An operator assigned to an `Active` or `Deferred` incident is treated as unavailable for new call discovery and transfer targeting.
- Transfer target options are filtered by both backend eligibility and realtime presence; only online, available operators with no active workbench are shown.

Caller call routing:
1. Caller captures the best available location and broadcasts `caller.operator.available.request`.
2. Available operators respond with `caller.operator.available.response`.
3. The first usable operator response receives the call request.
4. If the operator does not answer within `settings.call_timeout_seconds`, the caller excludes that operator and retries discovery.
5. If all available operators have already been attempted and none answered, the caller is informed that operators are busy and should try again later.

## Caller Side

The caller surface is a single-page flow with layered overlays.

Caller authentication is designed to avoid login friction during emergencies. Caller accounts keep a long-lived remembered session, currently controlled by `HOTLINE_CALLER_SESSION_LIFETIME`, while operator/admin surfaces keep the normal session policy.

### Caller Home

Base resting surface for the caller role.

Purpose:
- show the `Call for Help` hold-to-start control
- show caller utility actions such as history and availability
- act as the background surface under caller overlays

### Calling Overlay

Purpose:
- show that a call attempt is in progress but not connected
- cover both new outgoing calls and reconnect attempts while the latest call session is still `calling`
- allow caller hang-up before answer

### Current Incident Overlay

Purpose:
- show the currently open incident while no live call is in progress
- show incident details, messages, media, call history, and reconnect actions
- allow caller review without leaving Caller Home

### Active Call Modal

Purpose:
- show the connected live call experience
- host operator header, incident context, chat thread, chat composer, video preview, and call controls
- start live geolocation/orientation tracking while the call is active

Caller location behavior:
- initial call/discovery payload includes coordinates when available
- active calls use `watchPosition` plus orientation data when available
- updates are gated to avoid flooding realtime with insignificant changes
- location update signals include latitude, longitude, accuracy, altitude, altitude accuracy, heading, heading source, and captured timestamp when available

Caller overlay priority:
1. Caller Home is always the base surface.
2. Calling Overlay appears while a call is connecting.
3. Current Incident Overlay appears when there is an open incident but no connected live call.
4. Active Call Modal appears when the live call is connected.

## Operator Dashboard

The operator dashboard is the base dispatch surface. It remains mounted while workbench overlays open/close so the dashboard map and rails are not rebuilt unnecessarily.

Current dashboard elements:
- Surface chrome with app identity, alert/time block, and operator identity.
- MapLibre dashboard map using vendored MapLibre assets.
- Dark vector map style configured through `public/hotline.json`.
- PBB MapServer vector, glyph, terrain, and POI tile sources.
- Left floating rail: `Active + Deferred` incidents.
- Right floating rail: `Archive` and `Activity Log`.
- Bottom floating rail: team assignment kanban lanes.
- Incident markers on the map for current rail incidents.
- Helper map controls mounted in the navbar area.

Dashboard data flow:
- `/api/operator/dashboard` is trimmed to high-level dashboard state, pending transfer requests, available transfer targets, and lane metadata.
- Active/deferred incidents are loaded separately and used as the source for the left rail, map markers, and kanban cards.
- Archived incidents are loaded separately for `Resolved` and `Discarded` statuses.
- Team assignment lane cards are normalized from each active/deferred incident's `team_assignments`, not duplicated from `/dashboard`.

Dashboard map behavior:
- main map is view-only for normal dispatch use; native map zoom interactions are disabled
- dashboard map shows most recent incident location
- workbench mini-map shows latest caller location
- caller location window map shows track history for movement review

## Operator Modals

### Incoming Call Modal

Purpose:
- show a new incoming caller request targeted to the current operator
- show a reconnect request using the same shell
- provide answer and dismiss actions after the operator attempt exists

Current phases:
- `preparing`: shown immediately after realtime call request, before `/api/operator/call-attempts` returns `operator_attempt.id`; action row is blank and reserved so modal size does not shift
- `incoming`: brand-new caller request with answer/dismiss controls
- `reconnect`: reconnect request with answer/dismiss controls
- `connecting`: answer accepted and workbench is being prepared; controls hidden while layout remains stable

### Transfer Request Modal

Purpose:
- show a pending transfer request from another operator
- provide accept/reject actions before opening the transferred incident

### Outbound Transfer Modal

Purpose:
- send the current incident to another available operator
- show only realtime-online and available operators from the shared presence roster
- require a transfer reason

Target filtering:
- starts from backend `available_transfer_targets`
- intersects with `presence.global.hotline` roster entries
- excludes the current operator
- excludes operators that are busy, stale, expired, assigned, or in an active workbench

## Operator Workbench

The workbench is a fullscreen incident overlay. Opening the workbench shows a busy overlay while the incident request and render complete.

Major states:
- `active`: live call is in progress
- `inactive`: incident is open but there is no live call

Inactive action visibility:
- `Transfer`: shown when status is `Active` or `Deferred`
- `Discard`: shown when status is `Active` or `Deferred`, hidden when current status is already `Discarded`
- `Defer`: shown when status is `Active` or `Deferred`, hidden when current status is already `Deferred`
- `Resolved`: shown when status is `Active` or `Deferred`, hidden when current status is already `Resolved`
- `Close`: shown when status is not `Active`; closes the workbench only

Workbench layout:
- caller/incident summary column
- incident type editor
- dispatch/team assignment editor
- call-session timeline when inactive
- active call media controls when active
- caller chat column

Call-session timeline:
- an incident may have multiple call sessions
- inactive workbench presents each call session as a timeline card
- each card can contain an AudioCallSession component and a MediaStrip for the session
- audio media updates refresh AudioCallSession
- video media updates refresh MediaStrip

Caller location:
- workbench shows latest caller coordinates, accuracy, facing/heading, and elevation when available
- mini-map is clickable
- click opens a helper window component with a resizable/draggable MapLibre map
- window map renders latest location and caller movement history
- location updates are persisted by operator-side endpoint handling, including a history table

## Media Pipeline

Media recording runs in the browser and drains through IndexedDB-backed consumers.

Current flow:
1. Producer records operator audio, caller audio, and caller video when applicable.
2. Chunks are persisted locally to IndexedDB.
3. Consumers drain chunks to realtime or HTTP fallback transport.
4. On finalization, remaining chunks can be batch-flushed.
5. Server assembles media and broadcasts processing/availability events.
6. Operator workbench updates only the relevant UI component based on media type.

Important behavior:
- each consumer forwards one chunk at a time during normal drain
- before forwarding individual chunks, consumer checks record status so finalized records batch-flush instead of continuing slow single-chunk drain
- `media.available` for video updates MediaStrip
- `media.available` for audio updates AudioCallSession
- media availability is broadcast on `hotline.media.incident.{incidentId}`, allowing open incident views/workbenches to update even after the call has ended

## Maps

Map settings live in:
- `public/hotline.json`

Map sources:
- PBB MapServer: `https://mapserver.pbb.ph/`
- vector tiles: `/tiles/vector/{z}/{x}/{y}.pbf`
- POI tiles: `/tiles/poi/{z}/{x}/{y}.pbf`
- terrain tiles: `/tiles/terrain/{z}/{x}/{y}.png`
- glyphs: `/tiles/glyphs/{fontstack}/{range}.pbf`

Map theme:
- dark dashboard style
- translucent dashboard rails over the map
- no blur on floating rails

## Helper Library Usage

Hotline uses the local vendored helper library copy under:
- `public/vendor/helpers.pbb.ph/`

Currently used helper concepts include:
- tabs
- virtual list
- timeline
- kanban
- audio call session
- media strip
- busy overlay
- window manager
- map controls

When helper releases new components or fixes, refresh the vendored copy before using the new APIs in Hotline.

## Operator Maintenance Commands

### Clear Test Incidents

Operator-scoped command:
```bash
php artisan app:clear-test-incidents --operator-id=2 --force
```

Full test reset command:
```bash
php artisan app:clear-test-incidents --all --force
```

Purpose:
- bulk-delete operator incident records and submitted data attached to those incidents
- avoid deleting incidents one at a time during local QA or demo-data reset
- keep lookup/admin data intact, such as users, teams, incident categories, incident types, resource types, and team inventory

Expected delete scope:
- incidents owned by the selected operator when `--operator-id` is provided
- all incidents when `--all` is provided
- related call attempts, operator attempts, call sessions, participants, messages, attachments, media records, media chunks, incident type details, incident resources needed, transfers, team assignments, assignment resources, assignment notes, and caller location history

Expected behavior:
- command refuses to run without `--force`
- command requires exactly one scope selector: `--operator-id` or `--all`
- child records are deleted before incidents so foreign-key constraints do not block cleanup
- uploaded media/message files should be removed when file cleanup is supported; if file cleanup is not implemented, command output should explicitly say database records were removed but files may remain on disk

Expected operator-side result after running:
- Active + Deferred rail is empty for deleted incidents
- Archive rail is empty for deleted incidents
- map markers for deleted incidents disappear
- team assignment kanban lanes no longer show cards from deleted incidents
- activity log entries tied to deleted incidents disappear if they are stored as incident-derived records
- caller location tracks for deleted incidents disappear
- existing operator session/login state is not affected

Current status:
- command is implemented as `app:clear-test-incidents`
- current available custom commands include `app:check-hub-heartbeats`, `app:finalize-stale-call-media`, `app:prune-data-api-cache`, and `app:clear-test-incidents`
