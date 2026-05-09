# Citizen Protocol Inventory

Date: 2026-05-10

Status: Planning inventory snapshot

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
- citizen surface still calls some `/api/caller/*` endpoints
- citizen surface still requests `/api/realtime/admission/caller` in call/session paths
- citizen and operator surfaces publish/listen for `caller.*` Realtime events
- operator surface still uses `caller_id`, `caller_name`, `actual_caller_name`, `caller_location`, and related payload fields
- chat Helper mounts still use viewer role `caller`
- PWA manifest still points at `/caller.webmanifest`
- service worker still caches/navigates caller paths

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
- call creation writes `participant_role = caller`
- media logic treats `caller_video` as the citizen video capture type
- SITREP output still uses caller-shaped keys and prose
- admin delete-blocking labels still say caller

Migration classification:
- DB-backed names should not be destructively renamed first
- add citizen-facing serializers/accessors before schema changes
- keep old caller-shaped persistence readable until a later decommission
- decide whether protocol values such as `caller_video` and `participant_role = caller` are stable technical identifiers or user-role values to rename

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
- operator/media tests assert `caller_video`, `peer_role = caller`, and caller-shaped incident fields

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
- public API docs still list `/api/caller/*`
- Realtime spec still documents `caller.*` event names
- canonical contract docs still list role `caller`
- schema docs still use `caller_id`, `actual_caller_name`, and media role values
- Helper mapping still describes caller surface/adapters

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
- caller-to-citizen Helper work should start in Hotline-owned adapter names and payload normalization
- any shared Helper component/docs changes should be proposed upstream first
- Hotline vendor refresh should happen only after Helper changes land

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
