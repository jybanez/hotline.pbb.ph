# Citizen Live Call Readiness Checklist

Date: 2026-05-10

Status: Owner live testing complete for core call, reconnect, disconnect, offline, hangup, canonical write, and log-review checks.

Scope:
- Citizen-to-operator live call flow after the caller-to-citizen compatibility refactor.
- Hotline app served at `https://hotline.pbb.ph`.
- Shared Realtime service served at `https://realtime.pbb.ph`.

## Preflight Completed

- [x] Confirmed current branch is `codex/citizen-live-call-readiness`.
- [x] Stopped stale Laravel dev servers on ports `8020` and `8021`.
- [x] Confirmed Hotline is served by Apache/PHP 8.2 at `https://hotline.pbb.ph`.
- [x] Confirmed unauthenticated `/citizen` redirects to the app home.
- [x] Confirmed unauthenticated `/operator` redirects to the app home.
- [x] Confirmed Realtime daemon is running with `php artisan realtime:serve`.
- [x] Confirmed `wss://realtime.pbb.ph/realtime` accepts the Hotline origin.
- [x] Confirmed Realtime returns the initial `session.awaiting-auth` websocket envelope.
- [x] Confirmed Hotline Realtime settings are present:
  - `realtime_url`: `https://realtime.pbb.ph`
  - citizen admission project configured
  - operator admission project configured
  - media admission project configured
  - signing secret configured
  - backend secret configured
- [x] Applied pending citizen compatibility migration `2026_05_10_000001_add_citizen_id_columns_for_caller_compatibility`.
- [x] Confirmed all migrations have run.
- [x] Confirmed active live-test users exist:
  - `citizen`: 4 active
  - `operator`: 2 active
- [x] Confirmed existing rows with `caller_id` have matching `citizen_id` backfill:
  - `incidents`: 0 missing `citizen_id`
  - `call_attempts`: 0 missing `citizen_id`
  - `call_sessions`: 0 missing `citizen_id`
  - `incident_caller_locations`: 0 missing `citizen_id`

## Automated Gates

- [x] Focused PHP feature tests passed: 28 tests, 147 assertions.
- [x] Citizen Realtime JS contract test passed.
- [x] Citizen surface contract JS test passed.
- [x] Citizen Helper contract JS test passed.
- [x] Production asset build passed with the existing Vite mixed static/dynamic import warning.

Commands used:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe artisan test tests\Feature\Realtime\AdmissionTest.php tests\Feature\Citizen\CallAttemptFlowTest.php tests\Feature\Citizen\ReconnectFlowTest.php tests\Feature\Operator\AnswerCallAttemptTest.php tests\Feature\Operator\AvailabilityTest.php tests\Feature\Routing\SurfaceAccessTest.php
node tests\js\citizenRealtimeEvents.test.mjs
node tests\js\citizenSurfaceContracts.test.mjs
node tests\js\citizenHelperContracts.test.mjs
npm run build
```

## Owner Live Test

- [x] Open a citizen browser session at `https://hotline.pbb.ph`.
- [x] Sign in with an active citizen account.
- [x] Close or refresh any older `/caller` browser/PWA tabs before testing so legacy telemetry does not mask the canonical citizen flow.
- [x] Open a separate operator browser session at `https://hotline.pbb.ph`.
- [x] Sign in with an active operator account.
- [x] Set the operator available if the dashboard does not already show availability.
- [x] From the citizen surface, start a new call.
- [x] Confirm the operator receives the incoming call attempt.
- [x] Accept the call from the operator surface.
- [x] Confirm both sides transition to an active call session.
- [x] Confirm camera and microphone permission prompts work.
- [x] Confirm citizen hangup records a citizen outcome.
- [x] Confirm operator hangup still works.
- [x] Confirm reconnect works from an active incident.
- [x] Confirm no new `caller` values are written for canonical call outcomes or participant/media peer roles.
- [x] Review `storage/logs/laravel.log` for unexpected errors after the test.

## Owner Live Test Coverage Confirmed

- [x] New call answer reaches active call on citizen and operator surfaces.
- [x] Reconnect answer reaches active call on citizen and operator surfaces.
- [x] Operator decline for reconnect publishes and renders the declined state.
- [x] Reconnect ringing timeout follows `reconnect_timeout_seconds`.
- [x] Citizen cancel of unanswered reconnect publishes cancel/cancelled and clears the operator modal.
- [x] Operator hangup on reconnected active call closes the citizen live modal and refreshes operator UI.
- [x] Citizen hangup on reconnected active call confirms, ends the call, and refreshes operator UI.
- [x] Closing the citizen tab during an active call triggers operator remote-disconnect grace, cleanup API, and UI refresh.
- [x] Closing the operator tab during an active call triggers citizen operator-disconnect grace, cleanup API, and UI refresh.
- [x] Citizen offline during ringing pauses routing without misleading weak/available UI.
- [x] Citizen offline/online during active live call logs browser offline/online and keeps the active call stable.
- [x] Operator short offline during active reconnect call cancels grace when the operator returns online.
- [x] Operator long offline during active reconnect call triggers citizen heartbeat timeout and citizen cleanup.
- [x] Operator long offline during active reconnect call exits stale operator live-call UI locally and reconciles from server when online.
- [x] Operator long offline during fresh new active call exits stale operator live-call UI locally and reconciles from server when online.
- [x] Operator short offline during fresh new active call cancels grace and keeps the call live.
- [x] Recent live-test DB rows from call session `195` onward contain zero `caller` values in `call_sessions.outcome`, `call_participants.participant_role`, or `media.type`/`media.peer_role`.
- [x] Citizen incident read payloads omit legacy `caller_*` aliases while legacy `/api/caller/*` read endpoints keep compatibility aliases.
- [x] Recent live-test Laravel log window contains no ERROR, CRITICAL, or Exception entries.

## Keep Running During Live Test

- Realtime daemon: `php artisan realtime:serve` in `C:\wamp64\www\pbb\realtime`.
- Apache/WAMP virtual host for `https://hotline.pbb.ph`.
- Apache/WAMP proxy or virtual host for `https://realtime.pbb.ph/realtime`.

If the machine restarts, restart the Realtime daemon before retesting.

## Known Notes

- Legacy `/caller`, `/api/caller/*`, and caller event aliases remain during the compatibility window.
- Existing legacy tabs may still call `/api/realtime/admission/caller`; fresh `/citizen` sessions use `/api/realtime/admission/citizen`.
- Fresh `/api/citizen/incidents/*` read payloads use citizen-facing keys only for public-user identity/location/session aliases; legacy `/api/caller/incidents/*` remains alias-compatible.
- Canonical new Hotline writes now use citizen values where this phase has switched runtime behavior.
- Historical pre-refactor rows can still contain legacy caller values; the live-test verification above only covers newly written rows from this readiness pass.
- Destructive removal of caller columns, routes, PWA assets, and event aliases is still deferred until final decommission approval.
