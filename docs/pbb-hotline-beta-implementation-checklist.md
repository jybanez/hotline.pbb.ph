# PBB Hotline Beta Implementation Checklist

Date: 2026-04-04

Status: Active execution checklist

Use this as the working delivery checklist for the Beta team.

## Execution Status Update

Last updated: 2026-04-09

Status legend:
- `DONE` = implemented in the current Beta lane
- `PARTIAL` = scaffolded/foundation in place, but feature work still open
- `PENDING` = not implemented yet

Current snapshot:
- `DONE` Project framing is locked to Beta as a new build, not an Alpha refactor.
- `DONE` Browser-app baseline has separate Beta route files for `/`, `/caller`, `/operator`, and `/admin`.
- `DONE` Shared shell baseline exists with new Beta Blade shells, separate Vite entrypoints, and role-based route access.
- `DONE` Session/API baseline exists for `GET /api/bootstrap`, `GET /api/public/alert-level`, `POST /api/login`, `POST /api/logout`, `GET /api/user`, `POST /api/user`, `POST /api/user/password`, and `POST /api/reauth`.
- `DONE` Canonical Beta roles, alert levels, incident statuses, call statuses, call outcomes, operator runtime states, and team-assignment constants are implemented in code.
- `DONE` Phase 1 schema baseline now includes users, settings, incident definitions, incident/call lifecycle tables, messaging/media tables, transfers, team assignments, and activity logs.
- `DONE` Local MySQL baseline is configured for `pbb_hotline`, and the database has been migrated and seeded using PHP `C:/wamp64/bin/php/php8.2.29/php.exe`.
- `DONE` Beta app baseline now points at the assigned domain `https://hotline.pbb.ph/` in environment/config defaults.
- `DONE` New-call lifecycle baseline exists: caller new-call attempt creation/cancel, operator answer, incident creation on answer only, and first call-session participant records.
- `PARTIAL` Public, caller, operator, and admin surfaces now have Beta shells, separate Vite entrypoints, Helper navbar/auth shell wiring, shared Device Primer modal wiring, caller incident/reconnect views, operator incoming-call and transfer-review modals, operator workbench overlay restore, and baseline role-aware cards, but not the full role-specific UX from the spec yet.
- `PARTIAL` Operator incident APIs now cover dashboard lists, workbench reads, actual-caller edits, notes edits, transfer/team-assignment workflows, reconnect answer, and defer/discard/resolve state changes with resolve blocking on open team assignments.
- `PARTIAL` Admin APIs now cover summary, settings, user CRUD, incident-category CRUD, incident-type CRUD, incident-type-field CRUD, incident-type default-resource CRUD, team-category CRUD, team CRUD, team-inventory CRUD, resource-type-category CRUD, and resource-type CRUD with reference-aware hard-delete blocking.
- `PARTIAL` Feature tests now cover bootstrap, login/logout, role redirects, unauthorized behavior, admin summary, admin user CRUD/delete blocking, admin incident category/type/field/default-resource CRUD, admin team category/team/inventory CRUD, admin resource category/type CRUD, caller new-call attempt flow, operator answer flow, operator workbench/status flow, and baseline caller/operator availability behavior; the wider Phase 1 test matrix is still open.
- `PARTIAL` reconnect workflows, transfer request/accept/reject, team-assignment lifecycle APIs, incident message/media reads, and media-assembly completion admission are now implemented on the backend.
- `PARTIAL` live call controls, transfer handoff overlap, realtime chat/signaling transport, chunk capture/merge workers, and broader live settings propagation still need implementation; `alert_level` broadcast/update is now working through Realtime.

Required baseline references:
- [PBB Hotline Beta Proposal](./pbb-hotline-beta-proposal.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [project-audit-2026-04-03.md](./project-audit-2026-04-03.md)
- [database-schema-models.md](./database-schema-models.md)
- [hotline-helper-mapping.md](./hotline-helper-mapping.md)
- [PBB Laravel Application Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-laravel-application-baseline.md)
- [HQ Login Form Reference](C:/wamp64/www/pbb/hub.ph/docs/login-form-reference.md)
- [HQ Login / Logout Flow Reference](C:/wamp64/www/pbb/hub.ph/docs/login-logout-flow-reference.md)
- [PBB API Documentation Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-api-documentation-baseline.md)
- [PBB Helper Adoption Guide](C:/wamp64/www/pbb/hub.ph/docs/pbb-helper-adoption-guide.md)
- [PBB User Menu Spec](C:/wamp64/www/pbb/hub.ph/docs/pbb-user-menu-spec.md)
- [Hubs Page Layout Pattern](C:/wamp64/www/pbb/hub.ph/docs/page-layout-pattern-hubs.md)

## 1. Project Framing

- `DONE` Confirm Alpha/Beta naming:
  - `DONE` Alpha = current prototype
  - `DONE` Beta = new build
- `DONE` Confirm Beta is a new repository/app
- `DONE` Confirm Alpha remains available as reference during Beta work
- `DONE` Confirm local engineering baseline:
  - `DONE` PHP `C:/wamp64/bin/php/php8.2.29/php.exe`
  - `DONE` MySQL on `localhost`
  - `DONE` DB `pbb_hotline`
  - `DONE` user `root`
  - `DONE` blank password
- `DONE` Confirm Beta build directives:
  - `DONE` project location `c:/wamp64/www/pbb/hotline`
  - `DONE` assigned domain `https://hotline.pbb.ph/`
  - `PARTIAL` use the dark theme of the PBB library

## 2. Phase Planning

- `DONE` Lock Beta Phase 1:
  - `DONE` public home
  - `DONE` caller
  - `DONE` operator
  - `DONE` admin
  - `DONE` local incident operations
- `DONE` Lock Beta Phase 2:
  - `DONE` command
  - `DONE` announcements
- `DONE` Lock Beta Phase 3:
  - `DONE` SITREP generation
  - `DONE` Relay SITREP handoff
- `DONE` Lock post-SITREP phase:
  - `DONE` invite-based expanded conference participation

## 3. Canonical Contracts

- `DONE` Implement code constants for:
  - `DONE` roles
  - `DONE` alert levels
  - `DONE` incident statuses
  - `DONE` call statuses
  - `DONE` call outcomes
  - `DONE` operator runtime states
  - `DONE` team assignment statuses
  - `DONE` team cancellation reason codes
- `PARTIAL` Adopt the contract shapes in [pbb-hotline-beta-contracts.md](./pbb-hotline-beta-contracts.md)
- `DONE` Do not reintroduce Alpha vocabulary drift
- `DONE` Do not persist a `New` incident status in Phase 1
- `DONE` Create incidents only on answered calls and start them in `Active`

## 4. Data Layer

- `DONE` Start from Alpha domain semantics, not Alpha schema blindly
- `DONE` Carry forward core tables or equivalents for:
  - `DONE` users
  - `DONE` incidents
  - `DONE` call_attempts
  - `DONE` call_attempt_operator_attempts
  - `DONE` call_sessions
  - `DONE` incident messages and attachments
  - `DONE` media
  - `DONE` team assignments
  - `DONE` activity logs
  - `DONE` settings
- `DONE` Keep settings as a simple key-value table
- `DONE` Treat statuses and alert levels as code constants, not DB reference rows
- `PARTIAL` Enforce hard-delete blocking when records are referenced

## 5. Browser-App Baseline

- `DONE` Implement shell-first Laravel browser app structure
- `DONE` Implement `GET /api/bootstrap`
- `DONE` Implement modal-based login via Helper preset
- `DONE` Implement logout flow to `/`
- `DONE` Implement authenticated keepalive endpoint `GET /api/session/ping`
- `DONE` Implement activity-aware near-expiry session keepalive behavior
- `DONE` Implement re-auth modal behavior
- `DONE` Use re-auth as fallback when keepalive cannot preserve session
- `DONE` Keep caller terminals persistently authenticated via remember-auth restore unless explicitly logged out or invalidated
- `DONE` Preserve client-rendered operational UI
- `DONE` Split frontend into separate surface entrypoints:
  - `DONE` `/`
  - `DONE` `/caller`
  - `DONE` `/operator`
  - `DONE` `/admin`
  - `DONE` dynamic per-surface JS chunks now build separately from the shared runtime
  - `PARTIAL` surface-specific CSS extraction is underway:
    - `DONE` caller CSS moved out of `shared.css`
    - `PENDING` public/operator/admin CSS still need the same treatment

## 6. Shared Shell

- `DONE` Implement Helper navbar across all surfaces
- `DONE` Implement guest navbar:
  - `DONE` brand
  - `DONE` login
- `DONE` Implement authenticated user menu:
  - `DONE` account
  - `DONE` logout
- `DONE` Brand click behavior:
  - `DONE` guest -> `/`
  - `DONE` authenticated -> own role page
- `DONE` Implement unauthorized screen for wrong-role route access

## 7. Device Primer

- `PARTIAL` Implement operator startup primer
- `PARTIAL` Implement caller startup primer
- `DONE` Operator blocking checks:
  - `DONE` microphone
  - `DONE` audioPlayback
  - `DONE` mediaDevices
- `DONE` Operator warning-only checks:
  - `DONE` speechSynthesis
  - `DONE` notifications
- `DONE` Caller blocking checks:
  - `DONE` microphone
  - `DONE` audioPlayback
- `DONE` Caller warning-only checks:
  - `DONE` geolocation
  - `DONE` camera
  - `DONE` mediaDevices
- `DONE` Implement header warning status item
- `DONE` Reopen Device Primer on header status click

## 8. Public Home

- `DONE` Build `/` as a shared guest landing page
- `DONE` Use Helper login modal
- `DONE` Show current public alert level in a prominent card
- `DONE` Use fixed text tied to alert level
- `PENDING` Propagate alert-level changes live to connected guests
- `PENDING` Animate or notice live alert-level change

## 9. Caller Surface

- `DONE` Build caller home screen
- `PARTIAL` Implement combined network/operator-availability indicator
- `DONE` Use structured caller availability truth, not only a color string
- `DONE` Backend must derive:
  - `DONE` `call_service_ready`
  - `DONE` `available_operator_count`
  - `DONE` backend green/yellow/red recommendation
- `PARTIAL` Client may force local `red` when it cannot reach Hotline backend/session truth at all
- `DONE` Enforce immediate block on yellow/red call state
- `DONE` Implement press-and-hold call initiation
- `DONE` Implement dedicated caller calling state
- `DONE` Persist only real call attempts that were actually started
- `DONE` Do not create incident until operator answers
- `DONE` Build caller incident view
- `PARTIAL` Build caller live-call view
- `PARTIAL` Enable chat + attachments during live call
- `PARTIAL` Restrict attachments to photos/videos
- `PARTIAL` Enable caller video toggle and camera switching
- `PARTIAL` Implement caller self-preview wrapper
- `PARTIAL` Keep caller media strip incident-level and chronological
- `DONE` Exclude current open incident from recent-history list

## 10. Operator Surface

- `DONE` Build operator dashboard
- `PARTIAL` Implement canonical operator runtime state model:
  - `DONE` offline
  - `DONE` available
  - `DONE` engaged
  - `PENDING` transferring
  - `PENDING` reauth_required
- `DONE` Implement stat chips as live-computed optional metrics
- `DONE` Build active/deferred list
- `DONE` Build archive tab for resolved/discarded
- `PARTIAL` Build operator-specific activity log tab
- `DONE` Build map with all incidents shown by default
- `DONE` Implement list-to-map focus behavior
- `DONE` Implement glass-bubble marker action to open workbench
- `DONE` Implement color-coded map markers by incident status
- `PARTIAL` Build incoming-call modal
- `DONE` Show call type in incoming-call modal
- `DONE` Show reconnect incident id in reconnect modal
- `PENDING` Implement missed-call alert flow

## 11. Operator Workbench

- `DONE` Build full-screen overlay workbench
- `DONE` Open the workbench by fetching and rendering the selected incident payload inside the overlay
- `DONE` Keep browser URL on `/operator` while workbench is open
- `PARTIAL` Retain minimum client restore state:
  - `DONE` `active_incident_id`
  - `DONE` optional `active_call_session_id`
- `PENDING` Keep workbench active after hang-up until operator closes incident
- `DONE` Restore workbench after successful re-auth by re-fetching from retained client state
- `DONE` Optionally restore after refresh from session-scoped client state
- `DONE` Fall back to plain operator dashboard with notice if retained workbench context no longer resolves
- `PENDING` Implement live-call action set:
  - `PARTIAL` transfer
  - `PARTIAL` mute/unmute mic
  - `PARTIAL` hang-up
- `DONE` Implement non-live action set:
  - `DONE` defer
  - `DONE` discard
  - `DONE` resolve
- `DONE` Enforce resolve rule:
  - `DONE` all team assignments completed or cancelled first
- `DONE` Keep media/chat areas visible even when empty
- `DONE` Keep chat history read-only outside live call
- `PENDING` Implement live audiographs during live call
- `PARTIAL` Implement `processing media...` placeholders after call end
- `PENDING` Implement operator post-call audio playback with Helper audio session component
- `PENDING` Produce audio artifacts per peer per call session
- `PARTIAL` Do not collapse multiple operator voices into one role-level audio artifact
- `PENDING` Combine audio/video/media review chronologically across call sessions
- `PENDING` Preserve visible gaps between sessions

## 12. Call Routing

- `DONE` Implement new-call routing only to operators in `available` runtime state
- `PENDING` Implement failover to another available operator after timeout
- `DONE` Keep unanswered new-call attempts as lightweight records
- `DONE` Separate `call_attempts` from `call_sessions`
- `DONE` Add `call_participants` as the authoritative membership table for each call session
- `DONE` Implement child operator-attempt records per routed operator
- `DONE` Implement reconnect checks against assigned operator runtime state/current incident engagement
- `DONE` Create no reconnect attempt record when reconnect is blocked before send
- `DONE` Implement caller-side cancel for unanswered reconnect call sessions
- `DONE` Do not derive routing eligibility from incident ownership count alone

## 13. Transfer Flow

- `PARTIAL` Implement transfer modal with operator list + reason
- `DONE` Only show available operators
- `DONE` Implement receiving operator confirmation dialog
- `DONE` Reject -> current operator remains assigned
- `PENDING` Accept -> call remains connected
- `PENDING` Support short conference overlap during handoff
- `DONE` Make new operator assigned immediately after acceptance
- `PENDING` Move old operator to `transferring` runtime state during overlap
- `PENDING` Move new operator to `engaged` runtime state immediately on accepted transfer
- `DONE` Flip reconnect target to the new operator immediately on accepted transfer
- `DONE` Make the old operator read-only immediately on accepted transfer
- `PARTIAL` Allow only the new operator to mutate the incident during overlap

## 14. Admin Surface

- `DONE` Build `/admin` landing page with summary + quick counts + module grid
- `DONE` Make count cards actionable
- `DONE` Build full module pages for:
  - `DONE` users
  - `DONE` incident categories
  - `DONE` incident types
  - `DONE` incident type fields
  - `DONE` teams
  - `DONE` team categories
  - `DONE` resource categories and resource types
  - `DONE` settings
- `DONE` Use Hubs page-shell pattern on all module pages
- `DONE` Put primary `Add` button in the page header/toolbar
- `DONE` Use Helper grid for listing/search/sort/pagination
- `DONE` Keep row actions minimal and icon-based
- `DONE` Use Helper property editor for settings
- `DONE` Keep team resources inside Team edit flow
- `DONE` Keep incident type default resources inside Incident Type edit flow

## 15. Auth And Account Management

- `DONE` Use one email/password login flow for all roles
- `DONE` Redirect by role after login
- `DONE` Keep caller login persistent on-device unless explicitly logged out or the account is invalidated
- `DONE` No caller self-registration in Phase 1
- `DONE` Admin-created users default to `active`
- `DONE` Implement account modal via Helper account preset + extraRows:
  - `DONE` mobile
  - `DONE` avatar
- `DONE` Implement change-password modal via Helper preset
- `DONE` Make name/email/mobile/avatar editable in account flow

## 16. Deletes And Safety

- `DONE` Use hard delete when delete is allowed
- `DONE` Use Helper confirm dialog for deletes
- `DONE` Use strong record-specific confirmation messages
- `DONE` Always block delete if record is still referenced
- `DONE` Show detailed reference lists when blocked

## 17. Live Settings Behavior

- `PARTIAL` Implement live settings updates for:
  - `DONE` alert_level
  - `PARTIAL` call_hold_seconds
  - `PARTIAL` call_timeout_seconds
  - `PARTIAL` reconnect_timeout_seconds
  - `PARTIAL` alert_voice
  - `PARTIAL` audio_graph_style
- `PARTIAL` Apply immediately when safe
- `PENDING` Apply on next relevant action when live mid-session change would be disruptive
- `PARTIAL` Broadcast relevant changes live through Realtime

## 18. Realtime

- `DONE` Implement backend-issued admission only
- `PARTIAL` Define room naming for:
  - `PARTIAL` incident chat
  - `PARTIAL` call session
  - `DONE` settings broadcast room
- `PENDING` Implement presence
- `PENDING` Implement chat transport
- `PENDING` Implement attachment transport
- `PENDING` Implement call signaling
- `PARTIAL` Preserve channel join-readiness for future invite-based collaboration

## 19. Media

- `PARTIAL` Store files locally through the web app on mounted/shared storage
- `PENDING` Record per-peer audio chunks and caller video chunks during live call
- `PARTIAL` Keep the capture pipeline participant-scoped, not role-collapsed
- `PENDING` Merge chunks after call end
- `PARTIAL` Emit media records only after merge completion
- `PENDING` Show `processing media...` until media is ready
- `PARTIAL` Operator can access audio + video after merge
- `PARTIAL` Caller can access video only after merge

## 20. Testing

- `PARTIAL` Add feature tests for:
  - `DONE` bootstrap
  - `DONE` login/logout
  - `PENDING` re-auth
  - `DONE` role redirect
  - `DONE` unauthorized screen
  - `PENDING` Device Primer blocking rules
  - `PARTIAL` call initiation gating
  - `DONE` call attempt creation
  - `DONE` incident creation on answer only
  - `DONE` reconnect rules
  - `PARTIAL` transfer accept/reject/handoff
  - `DONE` resolve blocking on open team assignments
  - `PENDING` settings live update behavior
  - `DONE` delete blocking with reference details

## 21. Documentation

- `PARTIAL` Keep OpenAPI spec updated early
- `DONE` Document bootstrap/login/logout/account/password endpoints
- `DONE` Document caller/operator/admin surface endpoints
- `DONE` Document Realtime admission endpoints
- `PARTIAL` Keep contracts doc synchronized with implementation
