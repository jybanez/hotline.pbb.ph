# Citizen Protocol Inventory

Date: 2026-05-10

Status: Current inventory snapshot after Phase 2 compatibility slices and Helper documentation refresh

Related docs:
- [Citizen Protocol Migration Plan](citizen-protocol-migration-plan.md)
- [Citizen Protocol Migration Checklist](citizen-protocol-migration-checklist.md)

Scope:
- remaining `caller` usage in Hotline source contracts
- likely caller-to-citizen migration impact areas
- cross-project notes from the shared PBB chat log where Realtime or Helper affects sequencing

Excluded from source counts:
- `vendor/`
- `node_modules/`
- `public/vendor/`
- `public/build/`
- `storage/`
- `bootstrap/cache/`

## Scan Summary

The remaining `caller` usage is not a single rename class. It falls into these buckets:

- compatibility routes and role aliases that should intentionally remain during Phase 2
- database and Eloquent persistence contracts that need staged migration
- API request/response fields that need citizen aliases
- Realtime event names and payloads that need dual publish/listen behavior
- Helper-facing adapter and UI copy that should become citizen-facing
- media and call-session protocol values that need an explicit stability decision
- reports/SITREP keys and prose that need citizen-facing output aliases
- historical docs and tests that should be updated only after runtime behavior exists

## Current Snapshot After Phase 2 Slices

Current `caller` counts by source area, excluding `vendor/`, `node_modules/`, `public/vendor/`, `public/build/`, `storage/`, and `bootstrap/cache/`:

| Area | Remaining matches | Primary classification |
| --- | ---: | --- |
| `app/` | 236 | DB-backed persistence names, compatibility aliases, telemetry, deprecated enum cases, and model accessors |
| `routes/` | 20 | legacy `/caller` and caller-named operator route aliases kept during compatibility |
| `resources/` | 1,039 | citizen/operator Realtime compatibility, legacy payload aliases, CSS/data selectors, and UI copy still queued for later cleanup |
| `public/` | 10 | legacy caller PWA assets kept for installed app compatibility |
| `database/` | 31 | staged schema compatibility columns and legacy table/column names pending final decommission |
| `config/` | 1 | legacy environment variable fallback |
| `tests/` | 485 | explicit legacy compatibility coverage plus fixtures for DB-backed caller columns |
| `docs/` | 671 | migration plan/checklist text, historical Phase 1 docs, and current compatibility notes |

Top remaining resource files:
- `resources/js/surfaces/citizenSurface.js`
- `resources/js/surfaces/operatorSurface.js`
- `resources/css/citizen.css`
- `resources/js/surfaces/surfaceShared.js`
- `resources/js/realtime/citizenEvents.js`

Top remaining application files:
- `app/Http/Controllers/Api/Operator/IncidentController.php`
- `app/Support/Calls/CallRoutingService.php`
- `app/Support/Incidents/IncidentPayloadBuilder.php`
- `app/Http/Controllers/Api/Command/IncidentController.php`
- `app/Domain/Incidents/Models/Incident.php`

Intentional `caller` remains during compatibility:
- legacy public routes and PWA assets: `/caller`, `/caller/offline`, `/api/caller/*`, `caller.webmanifest`, and `caller-sw.js`
- legacy Realtime admission/events and telemetry while deployed clients migrate
- DB columns/tables such as `caller_id`, `actual_caller_name`, `caller_location_*`, and `incident_caller_locations` until final decommission
- legacy request/response aliases beside canonical citizen fields
- deprecated enum/protocol values such as `caller`, `caller_video`, `cancelled_by_caller`, and `ended_by_caller` where old rows or clients may still send/read them
- tests that assert legacy compatibility
- historical Phase 1 docs that are now explicitly marked as superseded or compatibility context

## Highest-Impact Runtime Files

### Frontend source

Primary files:
- `resources/js/surfaces/citizenSurface.js`
- `resources/js/surfaces/operatorSurface.js`
- `resources/js/surfaces/surfaceShared.js`
- `resources/js/surfaces/commandSurface.js`
- `resources/js/maps/workbenchLocationMap.js`
- `resources/css/citizen.css`
- `resources/views/pages/citizen/index.blade.php`
- `public/caller-sw.js`

Observed contracts:
- citizen surface uses `/api/citizen/*` endpoints where Hotline owns the frontend call path
- citizen surface requests `/api/realtime/admission/citizen`; `/api/realtime/admission/caller` remains a telemetry-backed legacy alias
- citizen and operator surfaces publish/listen for `citizen.*` Realtime events while accepting legacy `caller.*` events during compatibility
- operator surface still exposes legacy `caller_id`, `caller_name`, `actual_caller_name`, `caller_location`, and related payload fields beside citizen aliases where implemented
- Helper chat and call mounts now use `citizen` as the public-user viewer role while treating legacy `caller` messages as equivalent
- citizen PWA manifest and service worker are canonical; legacy caller PWA assets remain for installed app compatibility

Migration classification:
- API route names: migrate to citizen canonical aliases, keep caller aliases
- Realtime event names: migrate through dual publish/listen
- payload fields: add citizen aliases first, keep caller aliases
- CSS class names: migrate only if user-facing or externally meaningful; avoid churn for purely visual selectors unless touched
- PWA files: add citizen-named files, keep caller files for installed legacy PWAs

### Routes

Primary files:
- `routes/web/citizen.php`
- `routes/api/citizen.php`
- `routes/api/realtime.php`
- `routes/api/operator.php`

Observed contracts:
- `/caller` and `/caller/offline` are legacy aliases
- `/api/caller/*` is a legacy alias loop beside `/api/citizen/*`
- `/api/realtime/admission/caller` is a legacy alias
- operator routes still expose `caller-address`, `caller-location`, `caller-locations`, and `caller-cancel`

Migration classification:
- keep current caller aliases during compatibility
- add citizen-named operator aliases in Phase 2
- add tests proving route-pair equivalence
- add telemetry if legacy usage must be measured before decommission

### Backend domain and services

Primary files:
- `app/Support/Calls/CallRoutingService.php`
- `app/Support/Calls/CallSessionService.php`
- `app/Support/Incidents/IncidentPayloadBuilder.php`
- `app/Support/Citizen/CitizenHomePayloadBuilder.php`
- `app/Support/Realtime/RealtimeAdmissionService.php`
- `app/Support/Realtime/RealtimeEventPublishService.php`
- `app/Support/Media/MediaAssemblyService.php`
- `app/Support/Sitreps/SitrepGenerationService.php`
- `app/Support/Admin/BlockedDeleteInspectorService.php`
- `app/Http/Controllers/Api/Operator/IncidentController.php`
- `app/Http/Controllers/Api/Citizen/IncidentController.php`
- `app/Http/Controllers/Api/Citizen/CallAttemptController.php`

Observed contracts:
- persistence and authorization still rely on `caller_id`
- incident payloads expose `caller`, `caller_id`, `actual_caller_name`, `actual_caller_relationship`, and `caller_location`
- new call/session writes use citizen protocol values where the staged compatibility columns or enums exist
- media logic stores citizen protocol values while retaining legacy caller values as readable compatibility inputs
- SITREP output exposes citizen-facing keys/prose while retaining legacy caller keys where external consumers may still depend on them
- admin delete-blocking labels use citizen-facing copy while table checks still cover legacy caller-shaped persistence

Migration classification:
- DB-backed names should not be destructively renamed first
- add citizen-facing serializers/accessors before schema changes
- keep old caller-shaped persistence readable until a later decommission
- keep legacy protocol values such as `caller_video` and `participant_role = caller` readable until final decommission

### Database and models

Primary files:
- `database/migrations/2026_04_04_000009_create_incidents_table.php`
- `database/migrations/2026_04_04_000010_create_call_attempts_table.php`
- `database/migrations/2026_04_04_000012_create_call_sessions_table.php`
- `database/migrations/2026_04_28_000001_add_live_location_fields_to_incidents_table.php`
- `database/migrations/2026_04_28_000002_create_incident_caller_locations_table.php`
- `app/Domain/Incidents/Models/Incident.php`
- `app/Domain/Incidents/Models/IncidentCallerLocation.php`
- `app/Domain/Calls/Models/CallAttempt.php`
- `app/Domain/Calls/Models/CallSession.php`
- `app/Domain/Shared/Enums/UserRole.php`
- `app/Domain/Shared/Enums/CallOutcome.php`

Observed contracts:
- `incidents.caller_id`
- `incidents.actual_caller_name`
- `incidents.actual_caller_relationship`
- `incidents.caller_location_*`
- `incident_caller_locations`
- `incident_caller_locations.caller_id`
- `call_attempts.caller_id`
- `call_sessions.caller_id`
- enum compatibility for `caller`
- call outcomes like `ended_by_caller`

Migration classification:
- high-risk persistence contracts
- use accessors or additive columns before destructive renames
- keep historical outcomes stable unless reporting/analytics migration is approved

### Tests

Primary clusters:
- `tests/Feature/Citizen/*`
- `tests/Feature/Realtime/AdmissionTest.php`
- `tests/Feature/Routing/SurfaceAccessTest.php`
- `tests/Feature/Operator/*`
- `tests/Feature/Command/SitrepGenerationTest.php`
- `tests/Feature/Internal/MediaChunkIngressTest.php`

Observed contracts:
- many tests still seed `caller_id`
- route compatibility tests intentionally hit `/caller`
- Realtime tests now hit `/api/realtime/admission/citizen` with a legacy caller endpoint test
- operator/media tests assert citizen protocol values plus legacy caller compatibility where required

Migration classification:
- update tests alongside each runtime contract migration
- keep explicit legacy compatibility coverage until decommission
- do not bulk-rename fixtures before behavior changes

### Documentation and OpenAPI

Primary files:
- `docs/openapi/pbb-hotline-beta.yaml`
- `docs/pbb-hotline-beta-contracts.md`
- `docs/pbb-hotline-beta-realtime-spec.md`
- `docs/pbb-hotline-beta-api-inventory.md`
- `docs/pbb-hotline-beta-schema-draft.md`
- `docs/hotline-helper-mapping.md`

Observed contracts:
- public API docs list `/api/citizen/*` as canonical and `/api/caller/*` as temporary compatibility
- Realtime spec documents `citizen.*` event names and temporary `caller.*` compatibility
- canonical contract docs list role `citizen` and mark `caller` as legacy compatibility
- schema docs still use `caller_id`, `actual_caller_name`, and media role values where final DB decommission has not happened
- Helper mapping describes the citizen surface and keeps only legacy adapter aliases as compatibility notes

Migration classification:
- update docs after runtime compatibility exists
- mark older beta docs as historical if they describe the Phase 1 baseline
- update OpenAPI when citizen routes/payload aliases are implemented

## Cross-Project Notes

### Realtime

Shared chat log confirms:
- Realtime already implemented browser-originated `app.event.publish`
- sessions need `event.publish` capability to use it
- request payload shape is `{ event_type, data, correlation_id? }`
- the target room remains in the normal websocket envelope
- Realtime fans out an event envelope whose `type` is the requested event type and whose `payload` is the opaque `data`

Planning impact:
- Hotline can migrate `caller.*` to `citizen.*` event names within the existing app-event lane
- admission tokens must include `event.publish` wherever citizen/operator clients publish app events
- Realtime-side docs/fixtures should be updated to mention citizen event names after Hotline finalizes them
- caller event names should not remain as long-term aliases after the refactor is complete

### Helper

Shared chat log confirms:
- Helper changes should land upstream in `helpers.pbb.ph`
- Hotline should refresh vendored Helper copies from the official repository rather than patching shared Helper locally
- Helper owns visual/UI primitives, while Hotline owns domain adapters and Realtime mapping

Planning impact:
- caller-to-citizen Helper work is now covered in Hotline-owned adapter names, payload normalization, and smoke contracts
- no upstream Helper component change is required for the current citizen terminology slice
- Hotline vendor refresh is deferred unless a future upstream Helper change lands

## Proposed Classification Rules

Use these rules when changing code:

1. If `caller` is a user-facing label, prefer changing it to `citizen`.
2. If `caller` is a legacy route or role alias, keep it temporarily and add explicit compatibility tests until decommission.
3. If `caller` is a DB column/table name, migrate it physically to citizen naming through staged, reversible migrations.
4. If `caller` is an API payload field, add citizen aliases first and keep caller aliases.
5. If `caller` is a Realtime event name, replace it with a `citizen.*` event and keep caller support only temporarily if needed to complete the refactor safely.
6. If `caller` is a media/session protocol value, migrate it to citizen terminology through a compatibility window.
7. If `caller` is in historical docs, do not rewrite it unless the doc is being promoted to current canonical status.
8. If `caller` compatibility remains in runtime code, instrument usage before removing it.

## Recommended Next Implementation Slice

The safest first runtime PR after planning:

1. Add citizen-named operator route aliases.
2. Keep all current caller-named operator routes.
3. Add route-pair tests.
4. Add legacy caller route usage telemetry.
5. Update only low-risk frontend calls that point to operator/public API aliases, not DB schema or media protocol values.

This moves external API shape toward citizen terminology while avoiding the high-risk database, Realtime event, and media protocol migrations until their staging work is ready.
