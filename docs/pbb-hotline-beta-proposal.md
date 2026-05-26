# PBB Hotline Beta Proposal

Date: 2026-04-04

Status: Draft for new team kickoff

Migration note: The public emergency-reporting user is now `citizen`. Legacy `caller` routes, role values, payload fields, and installed PWA assets remain temporary compatibility contracts until the caller-to-citizen refactor is complete.

Related references:
- [project-audit-2026-04-03.md](./project-audit-2026-04-03.md)
- [database-schema-models.md](./database-schema-models.md)
- [hotline-helper-mapping.md](./hotline-helper-mapping.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta Implementation Checklist](./pbb-hotline-beta-implementation-checklist.md)
- [HQ Login Form Reference](C:/wamp64/www/pbb/hub.ph/docs/login-form-reference.md)
- [HQ Login / Logout Flow Reference](C:/wamp64/www/pbb/hub.ph/docs/login-logout-flow-reference.md)
- [PBB API Documentation Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-api-documentation-baseline.md)
- [PBB Helper Adoption Guide](C:/wamp64/www/pbb/hub.ph/docs/pbb-helper-adoption-guide.md)
- [PBB Laravel Application Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-laravel-application-baseline.md)
- [PBB User Menu Spec](C:/wamp64/www/pbb/hub.ph/docs/pbb-user-menu-spec.md)
- [Hubs Page Layout Pattern](C:/wamp64/www/pbb/hub.ph/docs/page-layout-pattern-hubs.md)

## Executive Summary

`PBB Hotline Alpha` should be treated as the working prototype and domain reference.

`PBB Hotline Beta` should be a new build that:
- preserves Hotline's proven domain model
- preserves the current barangay emergency-response process
- preserves the intent of the citizen and operator UX
- adopts current PBB platform libraries and application baselines from day one

Beta should not be an in-place refactor of Alpha.

## Why Beta Should Be A New Build

Alpha is already valuable as:
- workflow proof
- domain proof
- operator/citizen UX proof
- schema/model proof
- local-first operational proof

Alpha is not the right long-term application base because:
- authorization is not centralized enough
- controllers are too transport-aware and too dense
- custom realtime/media plumbing should move to Realtime and Helper contracts
- session/auth behavior should align directly to the PBB browser-app baseline
- the current single-bundle frontend structure is too coarse for the product's role-separated surfaces

## Product Context

Hotline is a barangay-local emergency response application used inside a `PBB Hub`.

The hub is the local physical and network environment that hosts multiple services and apps. Hotline Beta is one app inside that environment. Beta should remain app-focused and should not own hub/network infrastructure management.

Hotline Beta operates locally so that:
- citizens connect through barangay Wi-Fi
- operators work on LAN-connected terminals
- administration happens locally
- emergency intake continues even without internet connectivity

## PBB Hub Context

The `PBB Hub` is the on-prem barangay mini data-center environment.

Relevant hub-local services:
- `Web Server`
- `Local DNS Server`
- `DB Server`
- `File Server`
- `PBB Relay Server`
- `PBB Map Server`
- `PBB Realtime Server`
- `PBB Maestro Server`

Hotline Beta should consume these as local services through their APIs/SDKs and mounted/shared storage where applicable.

## Development Baseline

Current intended local engineering baseline:
- PHP: `C:/wamp64/bin/php/php8.2.29/php.exe`
- database engine: `MySQL`
- DB host: `localhost`
- DB name: `pbb_hotline`
- DB user: `root`
- DB password: blank

## What Beta Must Preserve

### Domain continuity
- users and roles
- incidents
- call attempts before incident creation
- call sessions after incident creation
- incident categories, types, fields, and reported details
- team definitions and team assignments
- media
- incident messages
- local settings

### Workflow continuity
- citizen initiates a new call only when operators are available and network is reachable
- unanswered new calls do not create incident records
- incident record is created only when an operator actually answers
- the first persisted incident status is `Active`
- reconnects target only the assigned operator
- operator manually manages incident statuses
- operator manually manages team assignments
- incident stays assigned to an operator until explicit transfer
- live call may hand over with a short multi-peer overlap during transfer
- operator remains engaged until the incident is deferred, discarded, resolved, or transferred away
- post-call audio must be stored per peer per session so playback can isolate voices even during transfer overlap

### Citizen availability continuity
- citizen green/yellow/red state must come from an explicit availability contract
- backend should own operator availability and call-service readiness truth
- client may still force `red` when it cannot reach Hotline backend/session truth at all

### Operator availability continuity
- operator availability must be defined by one canonical runtime state family
- an operator may own multiple incidents
- an operator may actively engage only one incident at a time
- only `available` operators are eligible for new-call routing
- reconnect may target only the assigned operator and only if that operator is:
  - `available`
  - or `engaged` on that same incident
- accepted transfer overlap should temporarily move the old operator into `transferring` and the new operator into `engaged`
- accepted transfer changes incident ownership immediately
- old operator becomes read-only immediately after transfer acceptance
- overlap is for call continuity only, not shared incident mutation

### Operational continuity
- local-first operation inside the hub
- local persistence of incidents, media, and conversation artifacts
- local map usage through MapServer
- local live interaction through Realtime
- local admin management

## Platform Alignment Direction

### HQ
Hotline Beta does not integrate directly with HQ as an app runtime dependency.

Current boundary:
- HQ manages network structure and topology upstream of Relay
- Hotline only needs the local topology consequence exposed through Relay or local hub configuration

### Relay
Use for:
- upstream/downstream inter-hub movement
- SITREP handoff in later phases
- inbound delivery of messages intended for Hotline when applicable

Current Beta scope with Relay:
- Phase 1 does not depend on Relay for core incident operations
- SITREP handoff comes later
- media handoff to Relay is future work after SITREP

### Realtime
Use for:
- websocket lifecycle
- room join/leave
- presence
- chat transport
- attachment transport
- call signaling
- conference capability during transfer overlap

Hotline still owns:
- routing rules
- operator runtime-state rules
- incident ownership rules
- timeout/failover policy
- reconnect rules
- persistence

### Helper
Use as the primary UI contract layer.

Current Beta direction:
- use Helper navbar for uniform app-shell behavior
- use Helper login, re-auth, account, and password presets
- align session expiry and keepalive behavior to the shared PBB session-handling and keepalive proposals
- use Helper Device Primer for operator and citizen startup readiness
- use Helper incident type and team assignment components
- use Helper chat thread/composer/upload queue/media strip/media viewer
- use Helper audio call-session playback for operator post-call review
- use Helper grid/icon/property-editor primitives for admin surfaces

### Maestro
Use for:
- worker/process visibility
- background media assembly telemetry
- operational job monitoring later as Beta grows

### MapServer
Use for:
- map tiles and map assets

## Recommended Beta Phasing

### Phase 1: Core Hotline Operations
- public `/` home page
- citizen surface at `/citizen`
- legacy caller surface alias at `/caller` until decommission
- operator surface at `/operator`
- admin surface at `/admin`
- session auth and re-auth
- activity-aware session keepalive
- local incident reporting
- new call routing
- reconnect flow
- transfer flow
- chat/media
- operator workbench
- admin module pages
- live local settings updates

### Phase 2: Command And Announcements
- command surface at `/command`
- command-focused oversight views
- announcements

### Phase 3: SITREP And Relay Handoff
- SITREP generation
- SITREP cadence by alert level
- Relay handoff for compact SITREP summary rollups and breakdown indexes
- API-backed drill-down for full detail when source-hub connectivity is available
- future aggregation SDK support for barangay -> city/municipality -> province -> region -> national rollups

### Phase 4: Post-SITREP Collaboration Expansion
- invite function for additional call participants
- broader conference-based collaboration if still needed

## Surface Model

### Public home
Route:
- `/`

Purpose:
- shared guest-facing landing page
- current public alert level
- login entry

Behavior:
- minimal navbar using Helper navbar
- brand + login only
- prominent alert card with fixed alert-level description
- logged-in users should be redirected to their own role page

### Citizen
Route:
- `/citizen`

Purpose:
- citizen home
- active incident view
- live call view
- recent incident history

### Operator
Route:
- `/operator`

Purpose:
- operator dashboard
- live incoming call handling
- full-screen incident workbench overlay

Workbench restore rule:
- the workbench remains an in-app overlay on `/operator`
- opening it should fetch the selected incident payload and render it inside the overlay
- browser URL change is not required
- re-auth should restore the same workbench from retained client state
- refresh may restore it from session-scoped client state; otherwise the operator returns to the dashboard

### Admin
Route:
- `/admin`

Purpose:
- module grid landing page
- full module pages for users, categories, types, fields, teams, resources, and settings

### Command
Route:
- `/command`

Status:
- reserved for later phase

## Frontend Structure Recommendation

Beta should not repeat Alpha's one-CSS/one-JS-for-everyone pattern.

Recommended entrypoints:
- public home bundle
- citizen bundle
- operator bundle
- admin bundle
- command bundle later

Recommended asset strategy:
- shared base/vendor chunk for common PBB and Helper dependencies
- page-specific bundles for role-specific logic and UI

## High-Level UX Direction

### Citizen
- home screen with press-and-hold `Call for Help`
- call only allowed when combined network/operator indicator is green
- blocked immediately on yellow or red
- Device Primer runs after login/page load
- active/deferred incident shows `Resume Call`
- resolved/discarded incidents do not show `Resume Call`
- live call surface uses operator header, chat thread/composer, video toggle, camera switch when needed, and hang-up

### Operator
- Device Primer runs after login/page load
- incoming calls use a blocking modal
- modal must identify `New Call` vs `Reconnect`
- answered call opens the incident workbench as a full-screen overlay
- no floating panels
- workbench remains active after hang-up until the incident reaches a closing operator state

### Admin
- admin landing page uses summary + actionable module cards
- module pages follow the Hubs page layout pattern
- add actions are page-level buttons
- edit/delete actions remain minimal and icon-based

## Current Beta Domain Decisions

- one generic `users` table with roles:
  - `citizen`
  - `caller` (legacy compatibility only)
  - `operator`
  - `command`
  - `admin`
- no citizen self-registration in Phase 1
- admin-created users start immediately as `active`
- delete actions are hard deletes, but must be blocked when records are still referenced
- blocked deletes should explain concrete references when practical

## Recommendation

Proceed with Beta as:
- a new app
- a PBB-baseline-aligned browser application
- a Helper-first UI implementation
- a Realtime-based local live-interaction app
- a later Relay/SITREP-integrated reporting system

Alpha remains the domain/process reference.
