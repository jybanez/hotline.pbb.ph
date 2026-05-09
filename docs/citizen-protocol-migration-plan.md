# Citizen Protocol Migration Plan

Date: 2026-05-10

Status: Phase 2 planning draft

Purpose:
- move the PBB Hotline canonical public user role from `caller` to `citizen`
- keep Hotline, Realtime, and Helper interoperable during the migration
- identify the contracts that must be migrated before old `caller` names can be retired

Tracker:
- [Citizen Protocol Migration Checklist](citizen-protocol-migration-checklist.md)

Inventory:
- [Citizen Protocol Inventory](citizen-protocol-inventory.md)

## Current State

Phase 1 established `citizen` as the user-facing surface name while keeping legacy `caller` compatibility:
- `/citizen` is the canonical public user surface
- `/caller` remains a legacy surface alias
- `/api/realtime/admission/citizen` is the canonical citizen admission path
- `/api/realtime/admission/caller` remains a legacy admission alias
- `citizen` and `caller` roles are treated as compatible public-user roles by middleware

Phase 2 should migrate the deeper protocol, payload, persistence, and shared-service contracts. This is higher risk than the surface rename because `caller` is still embedded in database columns, Realtime event names, Helper-facing adapters, media metadata, and historical reports.

## Migration Principles

1. `citizen` is the canonical term for new public-user contracts.
2. Existing `caller` contracts must remain readable and accepted only until the caller-to-citizen refactor is complete.
3. Prefer additive compatibility before destructive renames.
4. Database changes should be physically migrated to citizen names, but staged and reversible.
5. Realtime event migration should move to `citizen.*` canonical events without long-term caller aliases.
6. Tests should prove both the canonical citizen path and temporary legacy caller path during the compatibility period.
7. Telemetry should measure legacy caller usage before compatibility is removed.

## Contract Inventory

### Roles and Auth

Current compatibility:
- global role value `citizen`
- legacy role value `caller`
- middleware accepts citizen-compatible role values
- session config supports both `HOTLINE_CITIZEN_SESSION_LIFETIME` and `HOTLINE_CALLER_SESSION_LIFETIME`

Phase 2 work:
- confirm all new seeders, factories, authorization tests, and UI labels use `citizen`
- keep legacy `caller` role support until existing users are migrated or mapped
- add a later data cleanup plan for any persisted `caller` role values

### Web and API Routes

Canonical routes already exist for:
- `/citizen`
- `/citizen/offline`
- `/api/citizen/*`
- `/api/realtime/admission/citizen`

Legacy routes still exist and should remain during Phase 2:
- `/caller`
- `/caller/offline`
- `/api/caller/*`
- `/api/realtime/admission/caller`

### `/api/caller/*` Deprecation Timeline

The `/api/caller/*` public-user API prefix is a temporary compatibility alias for `/api/citizen/*`. It must remain available while deployed Hotline clients, installed legacy PWAs, Realtime flows, and Helper-backed UI paths are still being migrated.

Deprecation stages:
1. Compatibility stage: keep `/api/caller/*` enabled, log usage through `LogLegacyCallerRouteUsage`, and keep route-pair tests proving representative `/api/citizen/*` and `/api/caller/*` responses match.
2. Migration stage: update all Hotline frontend calls, Realtime examples, Helper examples, docs, and OpenAPI references to `/api/citizen/*`; continue telemetry collection for legacy caller traffic.
3. Removal-ready stage: proceed only after production telemetry shows no meaningful `/api/caller/*` traffic, deployed clients have moved to citizen routes, and the caller-to-citizen refactor is otherwise complete.
4. Decommission stage: remove `/api/caller/*` routes, legacy caller route tests, and caller-specific public API documentation in the final legacy decommission PR.

Removal must not happen before Realtime and Helper have confirmed their examples, fixtures, and any embedded Hotline API references are citizen-canonical.

Operator routes still expose caller terminology:
- `/api/operator/incidents/{incident}/actual-caller`
- `/api/operator/incidents/{incident}/caller-address`
- `/api/operator/incidents/{incident}/caller-location`
- `/api/operator/incidents/{incident}/caller-locations`
- `/api/operator/call-attempt-operator-attempts/{attempt}/caller-cancel`

Phase 2 work:
- add citizen-named aliases for operator routes
- keep caller-named operator routes as compatibility aliases
- update controllers and tests to treat citizen routes as canonical
- log usage of legacy caller routes before deprecation

### Database and Models

Known caller-shaped persistence contracts:
- `incidents.caller_id`
- `incidents.actual_caller_name`
- `incidents.actual_caller_relationship`
- `incidents.caller_location_accuracy`
- `incidents.caller_altitude`
- `incidents.caller_location_captured_at`
- `incident_caller_locations`
- `incident_caller_locations.caller_id`
- `call_attempts.caller_id`
- `call_sessions.caller_id`
- `call_participants.participant_role`
- `media.peer_role`

Known model/service touchpoints:
- `Incident::caller`
- `Incident::callerLocations`
- `IncidentPayloadBuilder`
- `CitizenHomePayloadBuilder`
- `CallRoutingService`
- `CallSessionService`
- `RealtimeAdmissionService`
- `RealtimeEventPublishService`
- `MediaAssemblyService`
- `SitrepGenerationService`
- `BlockedDeleteInspectorService`

Phase 2 work:
- do not hard-rename columns in the first Phase 2 implementation PR
- introduce citizen-facing accessors/serializers first
- physically migrate database columns/tables to citizen names after payload compatibility is proven
- use additive citizen columns/tables first, backfill from caller columns, switch writes, then keep reads dual-source until decommission
- create a later decommission migration for obsolete caller columns and tables after telemetry confirms legacy usage is gone

### API Payloads

Known caller-shaped fields:
- `caller`
- `caller_id`
- `actual_caller_name`
- `actual_caller_relationship`
- `caller_location`
- `caller_locations`
- `missing_caller_location_count`
- `caller_phone_numbers`
- `callers_assisted`

Phase 2 canonical citizen payload fields:
- `citizen`
- `citizen_id`
- `actual_citizen_name`
- `actual_citizen_relationship`
- `citizen_location`
- `citizen_locations`
- `missing_citizen_location_count`
- `citizen_phone_numbers`
- `citizens_assisted`

Compatibility rule:
- responses should include citizen fields as canonical
- caller fields may be included as aliases during compatibility
- requests should accept both citizen and caller field names where the operation is public-user related
- when both are provided, citizen fields should win and caller fields should be validated for consistency if possible

### Realtime Contracts

Known caller-shaped Realtime event families:
- `caller.operator.available.request`
- `caller.operator.available.response`
- `caller.call.request`
- `caller.call.ringing`
- `caller.call.cancel`
- `caller.call.cancelled`
- `caller.call.declined`
- `caller.call.answered`
- `caller.call.ready`
- `caller.location.updated`
- `caller.reconnect.*`

Known caller-shaped signal/payload fields:
- `caller_id`
- `caller_name`
- `caller_location`
- `caller-location`
- `viewerRole: caller`
- `remote role: caller`

Phase 2 work:
- define citizen event equivalents for each caller event
- update Hotline clients to publish citizen events
- keep any caller event handling temporary and remove it by the end of the refactor
- coordinate Realtime shared service fixtures and examples before removing caller event names
- add tests for citizen event behavior and temporary caller compatibility where it still exists

### Helper Contracts

Known Helper-facing caller concepts:
- `callerBootstrapAdapter()`
- `startCallAdapter()`
- `reconnectCallAdapter()`
- chat copy such as "Reply to caller..."
- empty-state copy such as "Caller chat remains visible..."
- Helper media viewer/call-session inputs using `peer_role`
- helper docs and proposals that still describe the public user as caller

Phase 2 work:
- rename Hotline-owned adapters to citizen names while keeping caller aliases where imported
- update Helper-facing payload adapters to emit citizen canonical fields
- update vendored Helper integration tests or smoke checks after adapter changes
- coordinate any upstream Helper examples, docs, or fixtures that still expect caller-shaped inputs

### Media and Call Session Contracts

Known caller-shaped media/session values:
- `caller_video`
- `caller-audio`
- `caller-cam-*`
- `peer_role = caller`
- `participant_role = caller`
- outcomes such as `cancelled_by_caller` and `ended_by_caller`

Phase 2 work:
- change media type values to citizen terminology where they describe the public user, for example `citizen_video`
- keep caller media values accepted only during the temporary compatibility window
- migrate `participant_role` and `peer_role` values from `caller` to `citizen`
- migrate caller-shaped call outcome values only through a staged compatibility path so existing history and reports remain readable during the refactor

### PWA and Assets

Known caller-shaped assets/config:
- `caller.webmanifest`
- `caller-sw.js`
- caller icon and cache names
- PWA bootstrap paths under caller/citizen route compatibility

Phase 2 work:
- add citizen-named PWA assets
- keep caller-named assets available for already-installed PWAs
- verify service-worker scope, cache names, manifest paths, and offline route behavior

### Reports, SITREP, and Docs

Known caller-shaped reporting terms:
- `callers_assisted`
- `caller_locations`
- `missing_caller_location_count`
- `caller_phone_numbers`
- documentation contract examples using `caller`

Phase 2 work:
- add citizen-named report keys while preserving legacy aliases if external consumers exist
- update SITREP labels and generated prose carefully so operational meaning is not lost
- update canonical contract docs after implementation, not before the compatibility behavior exists

## Implementation Sequence

### PR 1: Contract Map and Compatibility Helpers

Goal:
- document all caller-to-citizen contracts
- add central compatibility helpers where needed
- no database migration yet

Tasks:
- add this migration plan
- identify all caller-shaped route, payload, event, and DB contracts
- add a small compatibility helper for resolving citizen/caller request fields if implementation needs it
- add tests for helper behavior if code is introduced

Exit criteria:
- plan is reviewed
- no runtime behavior is changed unless covered by focused tests

### PR 2: API Payload Aliases

Goal:
- make API responses citizen-canonical while preserving caller aliases

Tasks:
- update incident payload serialization to expose citizen fields
- accept citizen request fields for public-user identity and location updates
- keep caller fields accepted
- add tests for citizen canonical fields and caller compatibility fields

Exit criteria:
- operator and citizen API tests pass
- consumers can read citizen fields without losing caller compatibility

### PR 3: Route Alias Expansion

Goal:
- add citizen-named aliases for operator/public-user operations

Tasks:
- add citizen aliases for actual-caller, caller-address, caller-location, caller-locations, and caller-cancel routes
- keep old caller routes
- update frontend calls to canonical citizen routes where the UI is citizen-facing
- log legacy caller route usage

Exit criteria:
- both citizen and caller routes pass equivalent tests
- legacy usage can be measured

### PR 4: Realtime Dual Event Support

Goal:
- move Realtime protocol toward citizen names without breaking old clients

Tasks:
- add citizen event names beside caller event names
- publish canonical citizen events where this app owns publication
- listen for both citizen and caller events
- update admission and event tests
- coordinate with the Realtime shared service before deprecating caller events

Exit criteria:
- citizen and operator clients can complete a call using citizen event names
- legacy caller events still work

### PR 5: Helper Adapter Migration

Goal:
- make Hotline-to-Helper adapters citizen-canonical

Tasks:
- rename Hotline-owned adapter functions to citizen names
- leave caller aliases if any imports still use them
- update Helper-facing payload maps
- refresh vendored Helper if upstream Helper changes are needed
- smoke-test citizen, operator, and media surfaces

Exit criteria:
- Helper-backed UI still renders and behaves correctly
- no known caller-only Helper dependency remains undocumented

### PR 6: Persistence Bridge

Goal:
- prepare database and models for citizen naming without breaking history

Tasks:
- add citizen-facing model accessors or additive citizen columns
- backfill if additive columns are chosen
- switch writes to citizen fields only after read compatibility is in place
- keep old caller fields readable
- add migration rollback coverage

Exit criteria:
- existing data still loads
- new writes are citizen-canonical or bridged predictably
- rollback does not strand required data

### PR 7: PWA and Asset Migration

Goal:
- make citizen PWA assets canonical

Tasks:
- add citizen manifest and service worker names
- retain caller assets for installed PWAs
- verify install/offline behavior for `/citizen`
- avoid invalidating critical offline caches without a fallback

Exit criteria:
- citizen PWA install/offline path works
- caller-installed PWA path still opens or redirects safely

### PR 8: Reports and Documentation

Goal:
- finish public terminology cleanup after protocol compatibility exists

Tasks:
- add citizen report keys and labels
- keep caller aliases only where external consumers still need them
- update contract docs, OpenAPI, and beta docs
- mark legacy caller contracts with deprecation notes

Exit criteria:
- docs match implemented behavior
- reporting output is citizen-canonical

### PR 9: Legacy Decommission

Goal:
- remove caller compatibility when the caller-to-citizen refactor is complete and telemetry shows legacy usage is gone

Prerequisites:
- no meaningful caller route usage
- no caller event usage from deployed clients
- Realtime and Helper have migrated
- installed PWA compatibility window has passed
- historical data export/reporting consumers are migrated

Tasks:
- remove caller route aliases
- remove caller event aliases
- remove caller request-field aliases
- remove old PWA assets only if safe
- run final schema rename/removal migrations if approved

Exit criteria:
- full test suite passes
- deployment notes include rollback and compatibility risks

## Test Matrix

Required test areas:
- auth and role compatibility
- `/citizen` canonical surface
- `/caller` legacy surface
- citizen and caller API route aliases
- citizen and caller Realtime admission
- citizen and caller Realtime event names
- incident payload serialization
- operator workbench call lifecycle
- citizen call lifecycle
- location update persistence
- media capture and assembly
- SITREP generation
- PWA manifest/service-worker behavior
- Helper adapter smoke coverage

## Deployment Notes

Recommended rollout:
1. deploy compatibility additions first
2. observe caller legacy usage
3. update Hotline clients to citizen canonical contracts
4. coordinate Realtime and Helper shared-service releases
5. migrate persistence only after protocol compatibility is stable
6. decommission caller only after telemetry confirms it is unused

Rollback principle:
- every Phase 2 runtime PR should be individually revertible without requiring immediate database rollback
- any database change should preserve caller-shaped reads until the final decommission phase

## Open Decisions

1. Resolved: database columns/tables should physically migrate from caller names to citizen names through staged, reversible migrations.
2. Resolved: media and protocol values such as `caller_video`, `peer_role = caller`, and `participant_role = caller` should migrate to citizen terminology.
3. Resolved: Realtime should use citizen event names without long-term caller aliases. Temporary caller compatibility may exist only until the refactor is complete.
4. Resolved: legacy caller compatibility should last until the caller-to-citizen refactor is complete, not indefinitely.
5. Resolved: legacy caller usage telemetry should be added before decommission.
6. Open: which Realtime and Helper repository changes need synchronized PRs before Hotline switches canonical client behavior?
