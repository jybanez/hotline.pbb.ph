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

Remove after the final compatibility window:

- `routes/web/citizen.php`: `/caller`, `/caller/`, `/caller/offline`.
- `routes/api/citizen.php`: `/api/caller/*` loop branch and `legacy.caller:public-api` middleware.
- `routes/api/realtime.php`: `POST /api/realtime/admission/caller`.
- `app/Http/Controllers/Api/Realtime/AdmissionController.php`: `caller()` delegating method.
- `app/Http/Middleware/LogLegacyCallerRouteUsage.php` and `bootstrap/app.php` alias registration.
- Route compatibility tests in `tests/Feature/Routing/SurfaceAccessTest.php`, `tests/Feature/Citizen/PublicApiCompatibilityTest.php`, and legacy sections of `tests/Feature/Realtime/AdmissionTest.php`.

## Batch 2: Realtime Event Aliases

Remove after Realtime service examples and fixtures are confirmed canonical:

- `resources/js/realtime/citizenEvents.js`: caller-to-citizen event map, reverse map, and legacy detector. Keep payload field aliasing until Batch 3.
- `resources/js/surfaces/citizenSurface.js` and `resources/js/surfaces/operatorSurface.js`: remaining `caller.*` event string inputs and `/api/realtime/legacy-caller-events` telemetry calls.
- `routes/api/realtime.php`: `POST /api/realtime/legacy-caller-events`.
- `app/Http/Controllers/Api/Realtime/LegacyCallerEventUsageController.php`.
- `tests/js/citizenRealtimeEvents.test.mjs` and `tests/Feature/Realtime/LegacyCallerEventUsageTest.php`.

Expected replacement: surfaces publish and compare `citizen.*` event names directly.

## Batch 3: Request Payload Aliases

Remove after route/event alias cleanup has shipped:

- `app/Support/Compatibility/LegacyCallerPayloadUsageLogger.php`.
- Legacy field acceptance in citizen/operator/media controllers:
  - `app/Http/Controllers/Api/Citizen/CallAttemptController.php`
  - `app/Http/Controllers/Api/Operator/CallAttemptController.php`
  - `app/Http/Controllers/Api/Operator/IncidentController.php`
  - `app/Http/Controllers/Api/Operator/CallSessionMediaController.php`
  - `app/Http/Controllers/Api/Media/AssemblyController.php`
  - `app/Http/Controllers/Api/Internal/MediaChunkIngressController.php`
- Tests whose only purpose is legacy caller payload logging.

Expected replacement: request validation accepts canonical `citizen_*` fields only, while durable database columns remain unchanged in this batch.

## Batch 4: PWA Alias Assets

Remove only after installed PWA compatibility window has passed:

- `public/caller.webmanifest`.
- `public/caller-sw.js`.
- `/caller/offline` route and `/caller` navigation fallback entries in `public/citizen-sw.js`.
- `window.HotlineCallerPwa` alias in `resources/js/entries/citizen.js`.

Keep `citizen-sw.js` cache cleanup for old `caller-pwa-*` caches until at least one additional production release after caller assets are removed.

## Batch 5: Durable Storage And History Names

Do not remove in the alias cleanup PR:

- Database columns and model attributes such as `caller_id`, `actual_caller_name`, `caller_location_*`.
- `incident_caller_locations` table and related historical relationships.
- Historical media values such as `caller_video` and `peer_role: caller`.
- Legacy role enum value `caller` and historical call outcomes such as `ended_by_caller`.
- SITREP/report labels that describe historical caller data.

Review status as of 2026-05-11:

- The following are durable schema/history names, not temporary runtime aliases: `incidents.caller_id`, `incidents.actual_caller_name`, `incidents.actual_caller_relationship`, `incidents.caller_location_*`, `call_attempts.caller_id`, `call_sessions.caller_id`, `incident_caller_locations`, `media.type = caller_video`, `media.peer_role = caller`, `call_participants.participant_role = caller`, and call outcomes such as `ended_by_caller`.
- Current report/SITREP payloads already expose citizen aliases beside legacy caller keys where external consumers may exist, including `citizens_assisted`, `citizen_locations`, `missing_citizen_location_count`, and `citizen_phone_numbers`.
- These durable names do not block Batch 2, Batch 3, or Batch 4 alias cleanup, provided those batches do not rename database columns, rewrite historical media rows, or remove legacy report keys.

Full removal needs a separate data migration plan, consumer notification window, backfill scripts, and explicit rollback strategy.

## Recommended First Removal PR

Realtime shared-service, Helper shared-service, installed PWA, and durable storage/history scope confirmations are complete. Start with Batch 2 as the first removal PR; it removes runtime event compatibility without touching routes, installed PWA assets, or database-backed history. Then run:

- `node tests/js/citizenRealtimeEvents.test.mjs`
- `npm run build`
- `php artisan test --filter=Realtime`
- Live smoke: new call, reconnect, operator hangup, citizen hangup, terminal status update.
