# Citizen Protocol Decommission Inventory

Status: prepared after local and production-served validation on 2026-05-11.

This inventory separates temporary caller compatibility from durable storage/history names. Do not remove database columns, historical media type values, or report terminology in the same batch as route/event alias cleanup.

## Validation Baseline

- Citizen clients now use `POST /api/realtime/admission/citizen` in local and production-served live calls.
- Legacy caller route telemetry stayed flat at 454 local entries, last seen at `2026-05-10 14:39:54`.
- Legacy caller Realtime event telemetry stayed at zero.
- Legacy caller payload telemetry stayed flat at 12 local entries, last seen at `2026-05-11 03:53:39`.
- Production terminal-status validation confirmed the citizen active incident clears after the terminal update and post-call reconcile.
- Realtime shared-service, Helper shared-service, installed PWA, and durable storage/history scope confirmations are complete.

## Batch 1: Route Aliases

Completed on branch `codex/citizen-live-call-readiness` after the final compatibility window:

- Removed `routes/web/citizen.php` `/caller`, `/caller/`, and `/caller/offline`.
- Removed `routes/api/citizen.php` `/api/caller/*` loop branch and `legacy.caller:public-api` middleware.
- Removed `routes/api/realtime.php` `POST /api/realtime/admission/caller`.
- Removed `routes/api/operator.php` caller-named operator compatibility aliases after citizen-named routes were already in place.
- Removed `app/Http/Controllers/Api/Realtime/AdmissionController.php` `caller()` delegating method.
- Removed `app/Http/Middleware/LogLegacyCallerRouteUsage.php` and `bootstrap/app.php` alias registration.
- Replaced route compatibility tests with route-removal coverage in `tests/Feature/Routing/SurfaceAccessTest.php`, `tests/Feature/Citizen/PublicApiCompatibilityTest.php`, `tests/Feature/Realtime/AdmissionTest.php`, and operator workbench tests.

## Batch 2: Realtime Event Aliases

Remove after Realtime service examples and fixtures are confirmed canonical:

- `resources/js/realtime/citizenEvents.js`: caller-to-citizen event map, reverse map, and legacy detector. Keep payload field aliasing until Batch 3.
- `resources/js/surfaces/citizenSurface.js` and `resources/js/surfaces/operatorSurface.js`: remaining `caller.*` event string inputs and `/api/realtime/legacy-caller-events` telemetry calls.
- `routes/api/realtime.php`: `POST /api/realtime/legacy-caller-events`.
- `app/Http/Controllers/Api/Realtime/LegacyCallerEventUsageController.php`.
- `tests/js/citizenRealtimeEvents.test.mjs` and `tests/Feature/Realtime/LegacyCallerEventUsageTest.php`.

Expected replacement: surfaces publish and compare `citizen.*` event names directly.

## Batch 3: Request Payload Aliases

Completed on branch `codex/citizen-live-call-readiness` after route/event alias cleanup shipped:

- Removed `app/Support/Compatibility/LegacyCallerPayloadUsageLogger.php`.
- Removed legacy field acceptance in citizen/operator/media controllers:
  - `app/Http/Controllers/Api/Citizen/CallAttemptController.php`
  - `app/Http/Controllers/Api/Operator/CallAttemptController.php`
  - `app/Http/Controllers/Api/Operator/IncidentController.php`
  - `app/Http/Controllers/Api/Operator/CallSessionMediaController.php`
  - `app/Http/Controllers/Api/Media/AssemblyController.php`
  - `app/Http/Controllers/Api/Internal/MediaChunkIngressController.php`
- Replaced tests whose only purpose was legacy caller payload logging with canonical-only validation coverage.

Replacement behavior: request validation accepts canonical `citizen_*`, `citizen_video`, and `peer_role: citizen|operator` fields only, while durable database columns and historical media values remain unchanged in this batch.

## Batch 4: PWA Alias Assets

Completed after installed PWA terminal-status validation passed:

- Removed `public/caller.webmanifest`.
- Removed `public/caller-sw.js`.
- Removed `/caller/offline` route in Batch 1, then removed `/caller` navigation fallback and `/caller` static cache entries from `public/citizen-sw.js`.
- Removed `window.HotlineCallerPwa` alias in `resources/js/entries/citizen.js`.
- Removed the remaining browser surface entry alias that allowed `renderSurface('caller')` to load the citizen surface.

Keep `citizen-sw.js` cache cleanup for old `caller-pwa-*` caches until at least one additional production release after caller assets are removed.

## Batch 5: Durable Storage And History Names

Implemented locally in [Citizen Durable Storage Migration Plan](citizen-durable-storage-migration-plan.md):

- Dropped database columns and table: `incidents.caller_id`, `incidents.actual_caller_name`, `incidents.actual_caller_relationship`, `incidents.caller_location_*`, `call_attempts.caller_id`, `call_sessions.caller_id`, and `incident_caller_locations`.
- Kept citizen storage as canonical: `citizen_id`, `actual_citizen_*`, `citizen_location_*`, and `incident_citizen_locations`.
- Historical media values and enum normalizers remain readable for old rows, while current migrations/tests ensure caller protocol values are converted to citizen values.
- Deprecated legacy read accessors remain internal compatibility only and resolve from citizen storage.

Review status as of 2026-05-11:

- The following are durable schema/history names, not temporary runtime aliases: `incidents.caller_id`, `incidents.actual_caller_name`, `incidents.actual_caller_relationship`, `incidents.caller_location_*`, `call_attempts.caller_id`, `call_sessions.caller_id`, `incident_caller_locations`, `media.type = caller_video`, `media.peer_role = caller`, `call_participants.participant_role = caller`, and call outcomes such as `ended_by_caller`.
- Current report/SITREP payloads already expose citizen aliases beside legacy caller keys where external consumers may exist, including `citizens_assisted`, `citizen_locations`, `missing_citizen_location_count`, and `citizen_phone_numbers`.
- These durable names do not block Batch 2, Batch 3, or Batch 4 alias cleanup, provided those batches do not rename database columns, rewrite historical media rows, or remove legacy report keys.

Full storage removal is implemented in code and verified locally. Production rollout still needs backup/release notes and a live smoke test.

## Recommended Verification

Realtime shared-service, Helper shared-service, installed PWA, and durable storage/history scope confirmations are complete. Batch 1, Batch 2, Batch 3, Batch 4, and Batch 5A-F are complete in code. Before production rollout, run:

- `node tests/js/citizenRealtimeEvents.test.mjs`
- `npm run build`
- `php artisan test tests\Feature`
- Live smoke: new call, reconnect, operator hangup, citizen hangup, terminal status update.
