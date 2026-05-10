# Citizen Live Call Readiness Checklist

Date: 2026-05-10

Status: Ready for owner live testing after local preflight.

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

- [ ] Open a citizen browser session at `https://hotline.pbb.ph`.
- [ ] Sign in with an active citizen account.
- [ ] Close or refresh any older `/caller` browser/PWA tabs before testing so legacy telemetry does not mask the canonical citizen flow.
- [ ] Open a separate operator browser session at `https://hotline.pbb.ph`.
- [ ] Sign in with an active operator account.
- [ ] Set the operator available if the dashboard does not already show availability.
- [ ] From the citizen surface, start a new call.
- [ ] Confirm the operator receives the incoming call attempt.
- [ ] Accept the call from the operator surface.
- [ ] Confirm both sides transition to an active call session.
- [ ] Confirm camera and microphone permission prompts work.
- [ ] Confirm citizen hangup records a citizen outcome.
- [ ] Confirm operator hangup still works.
- [ ] Confirm reconnect works from an active incident.
- [ ] Confirm no new `caller` values are written for canonical call outcomes or participant/media peer roles.
- [ ] Review `storage/logs/laravel.log` for unexpected errors after the test.

## Keep Running During Live Test

- Realtime daemon: `php artisan realtime:serve` in `C:\wamp64\www\pbb\realtime`.
- Apache/WAMP virtual host for `https://hotline.pbb.ph`.
- Apache/WAMP proxy or virtual host for `https://realtime.pbb.ph/realtime`.

If the machine restarts, restart the Realtime daemon before retesting.

## Known Notes

- Legacy `/caller`, `/api/caller/*`, and caller event aliases remain during the compatibility window.
- Existing legacy tabs may still call `/api/realtime/admission/caller`; fresh `/citizen` sessions use `/api/realtime/admission/citizen`.
- Canonical new Hotline writes now use citizen values where this phase has switched runtime behavior.
- Destructive removal of caller columns, routes, PWA assets, and event aliases is still deferred until final decommission approval.
