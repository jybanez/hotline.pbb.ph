# PBB Hotline Beta System Spec

Date: 2026-04-04

Status: Draft baseline spec

Primary baseline references:
- [PBB Laravel Application Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-laravel-application-baseline.md)
- [HQ Login Form Reference](C:/wamp64/www/pbb/hub.ph/docs/login-form-reference.md)
- [HQ Login / Logout Flow Reference](C:/wamp64/www/pbb/hub.ph/docs/login-logout-flow-reference.md)
- [PBB API Documentation Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-api-documentation-baseline.md)
- [PBB Helper Adoption Guide](C:/wamp64/www/pbb/hub.ph/docs/pbb-helper-adoption-guide.md)
- [PBB User Menu Spec](C:/wamp64/www/pbb/hub.ph/docs/pbb-user-menu-spec.md)
- [PBB User Session Handling Proposal](C:/wamp64/www/pbb/hub.ph/docs/pbb-user-session-handling-proposal.md)
- [PBB User Session Keepalive Proposal](C:/wamp64/www/pbb/hub.ph/docs/pbb-user-session-keepalive-proposal.md)
- [Hubs Page Layout Pattern](C:/wamp64/www/pbb/hub.ph/docs/page-layout-pattern-hubs.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)

## 1. Product Definition

`PBB Hotline Beta` is the barangay-local emergency response application for the next production line of Hotline.

It runs inside a `PBB Hub` and serves these Phase 1 roles:
- `caller`
- `operator`
- `admin`

Later roles/phases:
- `command`
- SITREP and Relay handoff

## 2. Alpha/Beta Positioning

- current project: `PBB Hotline Alpha`
- new build target: `PBB Hotline Beta`

Alpha remains the reference for:
- domain semantics
- schema/model direction
- workflow intent
- caller/operator UX direction

Beta is the clean implementation target.

## 3. Engineering Baseline

Current local engineering defaults:
- PHP executable: `C:/wamp64/bin/php/php8.2.29/php.exe`
- database engine: `MySQL`
- database host: `localhost`
- database name: `pbb_hotline`
- database user: `root`
- database password: blank

Phase 1 Beta build directives:
- target project location: `c:/wamp64/www/pbb/hotline`
- use the dark theme of the PBB library

## 4. Hub Deployment Context

Hotline Beta is a web application inside the local `PBB Hub`.

Relevant hub services:
- `Web Server`
- `Local DNS Server`
- `DB Server`
- `File Server`
- `PBB Relay Server`
- `PBB Map Server`
- `PBB Realtime Server`
- `PBB Maestro Server`

Hotline Beta assumptions:
- web app remains app-focused
- storage uses mounted/shared-path backing
- production DB can live on a dedicated MySQL server, but local development uses `localhost`
- Hotline consumes Realtime, Relay, Maestro, and MapServer through APIs/SDKs

## 5. Routing And Surface Layout

Phase 1 routes:
- `/`
- `/caller`
- `/operator`
- `/admin`

Later:
- `/command`

Role behavior:
- guests visiting protected routes are redirected to `/`
- login happens through the shared Helper login preset modal
- authenticated users are redirected to their role page after login
- authenticated users visiting the wrong role page see an unauthorized screen

Unauthorized screen:
- simple message
- button back to the user's proper home page

## 6. Frontend Bundle Model

Beta should use separate frontend entrypoints:
- public home bundle
- caller bundle
- operator bundle
- admin bundle
- command bundle later

Recommended asset strategy:
- shared base/vendor chunk for common dependencies
- surface-specific JS/CSS for role-specific behavior

## 7. Shared Navbar And User Menu

Use Helper navbar for app-shell uniformity.

Navbar behavior:
- guest brand click -> `/`
- authenticated brand click -> own role page

Public `/` navbar:
- brand
- `Login`

Authenticated user menu:
- use the PBB user-menu baseline
- keep only:
  - `Account`
  - `Logout`

Account flow:
- use Helper `createAccountFormModal(...)`
- use `extraRows` for:
  - `mobile`
  - `avatar`
- use Helper `createChangePasswordFormModal(...)` for password change

## 8. Roles

### Caller
- uses local Hotline caller surface
- may have only one open incident at a time
- may view current and past incidents

### Operator
- uses operator dashboard and workbench
- may own multiple incidents
- may actively engage only one incident at a time
- routing eligibility must be derived from canonical runtime state, not raw incident count

### Admin
- manages local setup, users, domain definitions, teams, resources, and settings

### Command
- deferred to later phase

### Operator runtime state

Phase 1 must define one canonical operator runtime state family:
- `offline`
- `available`
- `engaged`
- `transferring`
- `reauth_required`

Runtime meaning:
- `offline`
  - not authenticated, not connected, or not admitted enough for live operator work
- `available`
  - authenticated and operationally ready
  - owns no currently engaged incident
  - eligible for new-call routing
- `engaged`
  - currently owns the active workbench engagement for one incident
  - not eligible for new-call routing
  - may still receive reconnect only for that same incident
- `transferring`
  - temporary accepted-transfer overlap state
  - not eligible for new-call routing
  - remains tied to the incident being handed off
- `reauth_required`
  - session expired or re-auth is blocking continued operator activity
  - not eligible for routing

Phase 1 runtime rules:
- an operator may own multiple incidents
- an operator may engage only one incident at a time
- operator availability for routing is derived from runtime state, not ownership count
- UI wording may still say `busy`, but backend/business truth should use the canonical runtime state family above

## 9. Device Primer

### Operator Device Primer
Runs immediately after login/page load.

Blocking checks:
- `microphone`
- `audioPlayback`
- `mediaDevices`

Warning-only checks:
- `speechSynthesis`
- `notifications`

Post-entry behavior:
- warnings remain visible as a header status item
- clicking the status item reopens Device Primer

### Caller Device Primer
Runs immediately after login/page load.

Blocking checks:
- `microphone`
- `audioPlayback`

Warning-only checks:
- `geolocation`
- `camera`
- `mediaDevices`

Post-entry behavior:
- warnings remain visible as a header status item
- clicking the status item reopens Device Primer
- geolocation failure must not block calling if blocking checks passed

## 10. Public Home Page

Phase 1 public home page:
- shared guest landing page
- short Hotline description
- prominent alert card/panel
- `Login` entry

Alert card behavior:
- current alert level
- fixed description tied to each alert level
- no last-updated time
- live updates for connected guests
- small visual change notice/animation when alert level changes

## 11. Caller Surface

### Home screen
Navbar:
- brand
- avatar dropdown when authenticated

Main body:
- large `Call for Help` action
- press-and-hold required
- hold duration is setting-driven
- progress indicator beneath while holding

Bottom-left:
- recent incident list/drop-up

Bottom-right:
- combined availability/connectivity indicator
  - green = connected and operators available
  - yellow = connected but operators busy
  - red = not connected to network

Indicator truth model:
- this indicator should be treated as caller availability state, not a loose visual hint
- backend owns:
  - whether call service is ready
  - how many operators are currently in runtime state `available`
- client owns:
  - whether it can currently reach Hotline backend/session truth at all

Status rules:
- `green`
  - caller app can reach Hotline backend/session truth
  - backend call service is ready
  - at least one operator is in runtime state `available`
- `yellow`
  - caller app can reach Hotline backend/session truth
  - backend call service is ready
  - zero operators are in runtime state `available`
- `red`
  - caller app cannot reach Hotline backend/session truth, or
  - backend reports call service is not ready

Important separation:
- caller Device Primer warnings do not directly change this color unless they block calling locally
- geolocation warning must not change this color
- color and local call-button eligibility are related but not identical concerns

Call initiation:
- green -> allow call initiation
- yellow -> block immediately, show busy message
- red -> block immediately, show no-network message

### Caller calling state
After successful press-and-hold:
- full-screen calling state
- ringing animation
- `Calling operator...`
- hang-up button

If caller hangs up before answer:
- keep call-attempt record
- outcome = `cancelled_by_caller`

Reconnect ringing rule:
- if a reconnect has already created a new `call_session` but is still unanswered, caller hang-up should cancel that unanswered reconnect call session
- reconnect cancel should use outcome = `cancelled_by_caller`

### Caller incident view
Header behavior:
- `Active Incident` for active or deferred
- `Resolved Incident` for resolved
- `Discarded Incident` for discarded

Header content:
- incident title
- status
- date/time
- `Resume Call` only for active/deferred

Main body:
- incident type details

Footer:
- combined incident-level media strip
- hide when empty
- show `processing media...` while merged media is pending after a call

Closed incident behavior:
- same general layout as active incident
- read-only
- no `Resume Call`

Recent history:
- includes all past incidents
- excludes the current open incident
- item fields:
  - padded incident id
  - status
  - created date/time

### Caller live-call view
Header:
- operator details

Main body:
- chat thread
- chat composer

Footer actions:
- video toggle
- hang-up
- camera switch when live video is active

Rules:
- no mic mute action
- caller can send chat and attachments during live call
- attachments limited to photos and videos
- live video can be toggled on/off without ending call
- local self-preview appears in a floating draggable/resizable wrapper

## 12. Operator Surface

### Operator dashboard
Header:
- brand
- current alert level
- live date/time in the middle
- user menu on the right
- header warning status item for Device Primer warnings/problems

Main layout:
- stat chips
- 3-column dashboard
  - left: active + deferred incident list
  - center: map
  - right: archive/activity tab set
- bottom: team assignment stepper lanes

Incident list cards:
- caller avatar
- padded incident id
- status
- actual caller name
- created date/time

Archive tab:
- resolved and discarded incidents together

Activity log tab:
- operator-specific activity only
- includes:
  - operator actions
  - important events affecting the operator's incidents
- items should link back to the incident

Map behavior:
- default shows all incidents, including archived
- selecting a card focuses the incident location
- focused marker shows a glass bubble
- clicking the bubble opens the workbench
- markers are color coded:
  - `Active` = red
  - `Deferred` = amber
  - `Resolved` = green
  - `Discarded` = gray

### Incoming-call modal
Used for:
- `New Call`
- `Reconnect`

Content:
- caller avatar
- caller name
- call type label
- padded incident id for reconnects
- large ringing SVG with ripple animation
- answer button
- hang-up/decline button

If unanswered:
- close call modal
- show missed-call alert dialog

### Operator workbench
Opens immediately when a call is answered.

Presentation:
- full-screen overlay
- not a separate page
- no floating panels

Load model:
- the operator surface remains `/operator`
- opening the workbench should trigger a fetch for the selected incident payload
- recommended load source:
  - `GET /api/operator/incidents/{incident}`
- the fetched incident payload is then rendered inside the overlay workbench
- opening the workbench does not need to change the browser URL

Restore rules:
- opening the workbench from dashboard list, map bubble, or answered call should retain active workbench context in client state
- recommended minimum restore state:
  - `active_incident_id`
  - optional `active_call_session_id`
- successful re-auth should restore the same workbench by re-fetching from backend truth using that retained client state
- refresh may restore the same workbench if the frontend keeps that state in session-scoped browser storage
- if no retained workbench state exists after refresh, fall back to the plain operator dashboard
- if the referenced incident can no longer be opened after refresh or re-auth, clear retained workbench state and return to the dashboard with a notice

Lifecycle:
- workbench remains active even after hang-up
- operator remains `engaged` until incident becomes:
  - `Deferred`
  - `Discarded`
  - `Resolved`
  or is successfully transferred away

Top action rules:
- during live call:
  - `Transfer`
  - `Mute/Unmute Mic`
  - `Hang-up`
- when not on live call and incident is active/deferred:
  - `Defer`
  - `Discard`
  - `Resolve`

Resolve constraint:
- all team assignments must already be `Completed` or `Cancelled`

Location section:
- show map preview, coordinates, and resolved location text when coordinates exist
- show empty state when coordinates do not exist

Chat area:
- chat history remains visible after calls
- composer enabled only while both sides are on a live call
- outside live call, thread is read-only
- attachments limited to photos and videos

Media area:
- visible even when empty
- simple empty placeholder when nothing exists yet
- `processing media...` while merged media is not ready
- only caller live video shown during live call

Audio area:
- during live call, show live audiographs per role
- after live call, show processing state then operator playback
- playback uses separate audio tracks through Helper audio call-session component

Playback model:
- call history remains separate call-session cards
- audio playback is combined into one incident-level timeline with visible gaps between sessions
- media strip also combines chronology across sessions
- audio artifacts must be stored per peer per call session so replay can isolate each peer voice track
- audio storage must not assume only one caller and one operator track forever
- transfer overlap may create more than two peer audio artifacts in one call session
- call session membership must therefore be modeled through participant rows, not a single session-level operator reference

### Transfer behavior
Transfer action:
- opens modal
- shows available operators
- requires reason

Selected operator:
- receives confirmation dialog
- may accept or reject

If rejected:
- current operator remains assigned
- workbench stays with current operator

If accepted during live call:
- live call stays connected
- new operator becomes `engaged`
- old operator becomes `transferring` for the short overlap
- short conference overlap is allowed
- old operator can leave after handoff

Accepted-transfer ownership rule:
- the moment transfer is accepted, `incidents.operator_id` switches to the new operator
- reconnect target switches immediately to the new operator
- the new operator becomes the only operator with mutation rights on the incident
- the old operator becomes read-only immediately, even if still present in the overlap conference
- overlap exists for live call continuity only, not shared editing authority

During overlap, only the new operator may:
- edit incident details
- change incident status
- manage team assignments
- request further transfer actions

## 13. Admin Surface

### Admin landing page
Route:
- `/admin`

Layout:
- short welcome/summary section
- current alert level
- quick counts
- module grid

Quick counts:
- users
- teams
- incident types
- resource types

Count card behavior:
- opens related module page

### Module pages
Use the Hubs page-shell pattern:
- intro/header
- toolbar/actions
- main content row

Grouped admin module note:
- `Resources` owns both resource type categories and resource types
- resource types should reference categories by `category_id`, not store category as plain text

Toolbar:
- page-level primary `Add` button

Grid behavior:
- use Helper grid capabilities for search/sort/pagination

Row actions:
- minimal
- icon-based
- `Edit`
- `Delete` where allowed

Delete behavior:
- hard delete only when action is allowed
- always use Helper confirm dialog with stronger record-specific message
- delete must be blocked if record is referenced
- blocked delete should show detailed references when practical

### Admin modules in Phase 1
- Users
- Incident Categories
- Incident Types
- Incident Type Fields
- Teams
- Team Categories
- Resource Types
- Settings

Editing model:
- module page = full page
- add/edit = modal
- team resource inventories managed inside Team edit flow
- incident type default resources managed inside Incident Type edit flow
- settings use Helper property editor

## 14. Auth And Session Behavior

Login:
- use Helper login preset modal
- same email/password login flow for all roles

Role redirect after login:
- caller -> `/caller`
- operator -> `/operator`
- admin -> `/admin`
- command later -> `/command`

Logout:
- always return to `/`

Session preservation:
- Beta should follow the PBB session keepalive model for browser users
- use shorter normal Laravel session lifetime with near-expiry keepalive rather than stretching sessions indefinitely
- keepalive should run only when:
  - user is authenticated
  - page is visible
  - recent browser activity exists
  - session is near expiry
  - no re-auth modal is already open
- successful keepalive should refresh session touch state and avoid unnecessary re-auth interruption
- if keepalive fails, fall back to the normal re-auth flow

Session expiry:
- use re-auth modal
- re-auth is the fallback recovery path when keepalive cannot preserve the session
- successful re-auth returns user to current context
- wrong credentials keep modal open with inline errors
- canceling re-auth is treated as logout and returns user to `/`

Operator context note:
- for the operator surface, `current context` includes the current overlay workbench state
- re-auth should restore that workbench by re-fetching the active incident if retained workbench state still exists

Keepalive endpoint:
- Beta should expose a lightweight authenticated keepalive endpoint
- recommended shape:
  - `GET /api/session/ping`
- successful response should count as a normal server touch and may return refreshed CSRF/session metadata if needed

## 15. Live Settings Behavior

Admin-managed settings:
- `call_hold_seconds`
- `call_timeout_seconds`
- `reconnect_timeout_seconds`
- `alert_level`
- `alert_voice`
- `audio_graph_style`

Persistence model:
- simple key-value settings table

Runtime behavior:
- important setting changes should propagate live when safe
- disruptive changes should take effect on the next relevant action

Alert level live propagation:
- public home updates live
- caller updates live
- operator updates live with visual + spoken notice
- admin receives immediate live feedback

## 16. Call Routing Rules

### New call
- only routed to operators whose runtime state is `available`
- if one operator times out, try another available operator
- if no operator is available, caller is informed operators are busy
- incident record created only after an operator actually answers
- first persisted incident status is `Active`

### Reconnect
- initiated explicitly by caller through `Resume Call`
- only targets assigned operator
- before send, system checks whether assigned operator is:
  - in runtime state `available`
  - or in runtime state `engaged` on this same incident
- if not, reconnect is blocked before send and caller is told operator is busy
- blocked-before-send reconnect creates no attempt record

## 17. Command And SITREP Deferral

Phase 1 explicitly does not include:
- command surface
- announcements
- SITREP generation
- Relay SITREP handoff

These are deliberate later phases, not omissions.
