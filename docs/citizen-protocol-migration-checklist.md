# Citizen Protocol Migration Checklist

Date: 2026-05-10

Status: Working tracker for Phase 2 caller-to-citizen migration; live-call readiness and incident status propagation validated, route/event/request-field/PWA alias cleanup completed, and durable storage/history remains staged separately.

Related plan:
- [Citizen Protocol Migration Plan](citizen-protocol-migration-plan.md)

Related inventory:
- [Citizen Protocol Inventory](citizen-protocol-inventory.md)

Related durable storage plan:
- [Citizen Durable Storage Migration Plan](citizen-durable-storage-migration-plan.md)

Legend:
- `[x]` done
- `[ ]` todo
- `[~]` in progress or partially done
- `[blocked]` waiting on a decision, dependency, or external repository

## Phase 1 Baseline

- [x] Protect current working version on GitHub `main`.
- [x] Add canonical `/citizen` web surface.
- [x] Keep `/caller` as a legacy alias.
- [x] Add canonical `/api/citizen/*` public-user API prefix.
- [x] Keep `/api/caller/*` as a legacy alias.
- [x] Add canonical `/api/realtime/admission/citizen`.
- [x] Keep `/api/realtime/admission/caller` as a legacy alias.
- [x] Update user-facing surface copy from caller to citizen where already covered.
- [x] Keep role middleware compatible with both `citizen` and `caller`.
- [x] Add focused tests for citizen access and legacy caller compatibility.
- [x] Run full test suite after Phase 1 compatibility changes.

## Planning and Inventory

- [x] Create Phase 2 migration plan.
- [x] Create Phase 2 implementation checklist.
- [x] Create Phase 2 caller contract inventory snapshot.
- [ ] Review caller-to-citizen plan with Hotline owner.
- [ ] Review Realtime-facing event and admission changes with Realtime service owner.
- [ ] Review Helper-facing adapter and UI changes with Helper service owner.
- [ ] Confirm whether the canonical public-user term is always `citizen` across PBB ecosystem docs.
- [x] Decide support window for legacy `caller` routes, fields, events, and PWA assets: support until the caller-to-citizen refactor is complete.
- [x] Decide whether telemetry is required before decommissioning legacy caller contracts: yes.

## Contract Inventory Tasks

- [x] Inventory all remaining `caller` strings in `app/`.
- [x] Inventory all remaining `caller` strings in `routes/`.
- [x] Inventory all remaining `caller` strings in `resources/`.
- [x] Inventory all remaining `caller` strings in `public/`.
- [x] Inventory all remaining `caller` strings in `database/`.
- [x] Inventory all remaining `caller` strings in `config/`.
- [x] Inventory all remaining `caller` strings in `tests/`.
- [x] Inventory all remaining `caller` strings in `docs/`.
- [x] Classify each remaining `caller` usage as user-facing copy, internal code, API contract, DB contract, Realtime contract, Helper contract, report output, test fixture, or historical documentation.
- [x] Mark usages that should intentionally remain `caller` for compatibility or historical accuracy.

## Roles and Auth

- [x] Canonical citizen route access is available.
- [x] Legacy caller route access still works for compatible users.
- [x] Session lifetime supports `HOTLINE_CITIZEN_SESSION_LIFETIME`.
- [x] Session lifetime keeps `HOTLINE_CALLER_SESSION_LIFETIME` fallback compatibility.
- [x] Confirm all new seed users use `citizen`.
- [x] Confirm factories and test helpers default to `citizen` where appropriate.
- [x] Add migration or mapping plan for persisted users with role value `caller`.
- [x] Add deprecation note for the legacy `caller` role value.
- [x] Add tests for any role migration or alias behavior introduced in Phase 2.

## Web Routes and PWA

- [x] `/citizen` is canonical.
- [x] `/caller` compatibility was provided during the support window and has now been removed in Batch 1 decommission.
- [x] `/citizen/offline` exists.
- [x] `/caller/offline` compatibility was provided during the support window and has now been removed in Batch 1 decommission.
- [x] Add or verify `citizen.webmanifest`.
- [x] Add or verify `citizen-sw.js`.
- [x] `caller.webmanifest` compatibility was provided during the installed PWA support window and has now been removed in Batch 4 decommission.
- [x] `caller-sw.js` compatibility was provided during the installed PWA support window and has now been removed in Batch 4 decommission.
- [x] Verify service-worker scope for `/citizen`.
- [x] Verify offline behavior for `/citizen/offline`.
- [x] Verify legacy installed caller PWA opens or redirects safely.
- [x] Add PWA cache-name migration notes if cache keys include caller terminology.

## Public-User API Routes

- [x] `/api/citizen/*` prefix exists.
- [x] `/api/caller/*` compatibility was provided during the support window and has now been removed in Batch 1 decommission.
- [x] Confirm all citizen surface frontend calls use `/api/citizen/*` where possible.
- [x] Add tests proving `/api/citizen/*` and `/api/caller/*` route pairs return equivalent results during compatibility.
- [x] Add legacy caller public API usage logging if telemetry is required.
- [x] Document deprecation timeline for `/api/caller/*`.

## Operator API Routes

- [x] Add canonical citizen alias for `/api/operator/incidents/{incident}/actual-caller`.
- [x] Add canonical citizen alias for `/api/operator/incidents/{incident}/caller-address`.
- [x] Add canonical citizen alias for `/api/operator/incidents/{incident}/caller-location`.
- [x] Add canonical citizen alias for `/api/operator/incidents/{incident}/caller-locations`.
- [x] Add canonical citizen alias for `/api/operator/call-attempt-operator-attempts/{attempt}/caller-cancel`.
- [x] Caller-named operator compatibility routes were kept during the support window and have now been removed in Batch 1 decommission.
- [x] Update operator frontend calls to use citizen-named routes where appropriate.
- [x] Add route tests for each citizen alias.
- [x] Replace route compatibility tests with route-removal coverage after Batch 1 decommission.
- [x] Add legacy caller operator route usage logging if telemetry is required.

## API Payloads

- [x] Add canonical `citizen` object beside legacy `caller` object in incident payloads.
- [x] Add canonical `citizen_id` beside legacy `caller_id` where payloads expose public-user identity.
- [x] Add canonical `actual_citizen_name` request/response support.
- [x] Add canonical `actual_citizen_relationship` request/response support.
- [x] Add canonical `citizen_location` beside legacy `caller_location`.
- [x] Add canonical `citizen_locations` beside legacy `caller_locations`.
- [x] Add canonical `missing_citizen_location_count` beside legacy `missing_caller_location_count`.
- [x] Add canonical `citizen_phone_numbers` beside legacy `caller_phone_numbers`.
- [x] Add canonical `citizens_assisted` beside legacy `callers_assisted`.
- [x] Accept both citizen and caller request fields during compatibility.
- [x] Define conflict behavior when both citizen and caller request fields are provided: canonical non-empty `citizen_*` values win over legacy `caller_*` aliases.
- [x] Add serialization tests for citizen canonical fields.
- [x] Add compatibility tests for legacy caller fields.

## Database and Models

- [x] Decide whether database columns will be physically renamed or wrapped by citizen-facing accessors: physically migrate to citizen names through staged migrations.
- [x] Decide whether `incident_caller_locations` should eventually become `incident_citizen_locations`: yes.
- [x] Add model accessors or DTO layer for citizen-facing reads.
- [x] Add citizen-facing relationship names where useful, such as `Incident::citizen`.
- [x] Keep caller-facing relationship names during compatibility.
- [x] Add nullable citizen columns/tables first where a direct rename would be risky.
- [x] Backfill citizen columns/tables from caller columns/tables.
- [x] Switch writes after read compatibility exists.
- [x] Add rollback-safe migrations.
- [~] Plan destructive caller column/table removal through staged Batch 5 storage migration.
- [x] Update `BlockedDeleteInspectorService` labels to citizen-facing wording without breaking table checks.

## Realtime Admission

- [x] `/api/realtime/admission/citizen` is canonical.
- [x] `/api/realtime/admission/caller` compatibility was provided during the support window and has now been removed in Batch 1 decommission.
- [x] `RealtimeAdmissionService::forCitizen()` exists.
- [x] Legacy caller admission wrapper was provided during the support window and has now been removed in Batch 1 decommission.
- [x] Confirm Realtime shared service documentation uses citizen admission path: Hotline owns `/api/realtime/admission/citizen`; Realtime docs do not own that endpoint path.
- [x] Add Realtime-side fixture/example updates if needed: needed in Realtime docs/tests; scope recorded in [Citizen Realtime Coordination](citizen-realtime-coordination.md).
- [x] Add telemetry for legacy caller admission path.

## Realtime Events

- [x] Define citizen equivalent for `caller.operator.available.request`.
- [x] Define citizen equivalent for `caller.operator.available.response`.
- [x] Define citizen equivalent for `caller.call.request`.
- [x] Define citizen equivalent for `caller.call.ringing`.
- [x] Define citizen equivalent for `caller.call.cancel`.
- [x] Define citizen equivalent for `caller.call.cancelled`.
- [x] Define citizen equivalent for `caller.call.declined`.
- [x] Define citizen equivalent for `caller.call.answered`.
- [x] Define citizen equivalent for `caller.call.ready`.
- [x] Define citizen equivalent for `caller.location.updated`.
- [x] Define citizen equivalents for `caller.reconnect.*`.
- [x] Update citizen client to publish canonical citizen events.
- [x] Update citizen client to listen for citizen events.
- [x] Update operator client to publish canonical citizen events where Hotline owns publication.
- [x] Update operator client to listen for citizen events.
- [x] Update Realtime payload fields from `caller_id` to include `citizen_id`.
- [x] Update Realtime payload fields from `caller_name` to include `citizen_name`.
- [x] Add tests for canonical citizen event flow.
- [x] Add temporary compatibility tests only where caller event support still exists during the refactor.
- [x] Coordinate caller event removal with Realtime service owner: chat-log note posted and removal prerequisites recorded in [Citizen Realtime Coordination](citizen-realtime-coordination.md).
- [x] Add legacy caller Realtime event usage telemetry before removing caller event handling.

## Helper Integration

- [x] Rename Hotline-owned `callerBootstrapAdapter()` to a citizen-named adapter.
- [x] Rename Hotline-owned `startCallAdapter()` if its caller meaning is public-user specific.
- [x] Rename Hotline-owned `reconnectCallAdapter()` if its caller meaning is public-user specific.
- [x] Keep caller-named adapter aliases only until the refactor is complete.
- [x] Update Helper-facing adapter payloads to emit citizen canonical fields.
- [x] Update Helper-facing adapter payloads to accept legacy caller fields.
- [x] Update Helper docs or proposals that still describe the public user as caller.
- [x] Refresh vendored Helper copy if upstream Helper changes are needed: not needed for this Hotline-owned terminology slice.
- [x] Smoke-test Helper-backed citizen chat, media viewer, forms, and call UI.

## Media and Call Session Contracts

- [x] Decide whether `caller_video` should become `citizen_video`: yes.
- [x] Decide whether `caller-audio` keys should become `citizen-audio`: yes.
- [x] Decide whether `caller-cam-*` segment keys should become `citizen-cam-*`: yes.
- [x] Decide whether `peer_role = caller` should become `peer_role = citizen`: yes.
- [x] Decide whether `participant_role = caller` should become `participant_role = citizen`: yes.
- [x] Decide whether outcomes like `cancelled_by_caller` and `ended_by_caller` should become citizen-named outcome values in the same protocol migration: yes, canonical new values are `cancelled_by_citizen` and `ended_by_citizen`.
- [x] Accept both caller and citizen media values during temporary compatibility.
- [x] Add tests for old call sessions and new call sessions.
- [x] Verify media assembly still works with legacy records.
- [x] Verify operator media playback still filters correctly.
- [x] Verify citizen media visibility rules still hold.

## Reports and SITREP

- [x] Add citizen-facing report label for callers assisted.
- [x] Add `citizens_assisted` beside `callers_assisted` if report payloads are consumed externally.
- [x] Add `citizen_locations` beside `caller_locations` if report payloads are consumed externally.
- [x] Add `missing_citizen_location_count` beside `missing_caller_location_count`.
- [x] Add `citizen_phone_numbers` redaction key beside `caller_phone_numbers` if needed.
- [x] Update generated SITREP prose from caller to citizen where user-facing.
- [x] Keep historical or schema-specific caller terms where required.
- [x] Add SITREP tests for citizen-facing output.

## Docs and OpenAPI

- [x] Update `docs/pbb-hotline-beta-contracts.md` roles from caller to citizen after behavior exists.
- [x] Update `docs/pbb-hotline-beta-realtime-spec.md` with citizen events and caller decommission notes.
- [x] Update `docs/pbb-hotline-beta-api-inventory.md` with citizen routes and fields.
- [x] Update `docs/pbb-hotline-beta-schema-draft.md` with migration notes.
- [x] Update `docs/hotline-helper-mapping.md` from caller surface to citizen surface.
- [x] Update `docs/openapi/pbb-hotline-beta.yaml` with citizen API contracts.
- [x] Mark old caller docs as historical or deprecated instead of silently rewriting old architecture records.

## Testing Gates

- [x] Run focused routing tests after route alias changes.
- [x] Run focused auth/session tests after role changes.
- [x] Run focused Realtime admission tests after admission changes.
- [x] Run focused Realtime client tests after event changes.
- [x] Run focused operator workbench tests after API/payload changes.
- [x] Run focused citizen surface tests after frontend API changes.
- [x] Run focused media tests after media contract changes.
- [x] Run focused SITREP tests after report changes.
- [x] Run PWA/offline smoke test after manifest/service-worker changes.
- [x] Run Helper integration smoke test after Helper adapter changes.
- [x] Live-validate post-call incident status propagation: `Deferred` remains current/open, while `Discarded` and `Resolved` clear the citizen active incident through Realtime updates with post-call `/api/citizen/home` reconciliation as backup; the 2026-05-11 04:09 Discarded test published `hotline.incident.updated` from operator and the citizen surface applied it for incident 198, and the 04:11 and 04:14 terminal-status tests applied updates for incidents 199 and 200 before the next reconcile saw no current incident.
- [x] Run full test suite before merging each runtime PR.

## Deployment and Decommission

- [~] Deploy citizen compatibility additions before removing caller contracts: local WAMP runtime migrated and owner live testing passed; final production deployment remains open.
- [~] Monitor legacy caller route usage: local log snapshot on 2026-05-11 shows 454 local legacy route hits, all `POST /api/realtime/admission/caller`, last seen at `2026-05-10 14:39:54`; the 2026-05-11 04:06, 04:11, 04:14, 04:20, and production-served 04:17 live calls used `/api/realtime/admission/citizen`, and the 04:09, 04:11, 04:14, and 04:21 terminal status updates did not increase legacy route telemetry; testing logs also contain expected legacy route coverage hits.
- [~] Monitor legacy caller Realtime event usage: local log snapshot on 2026-05-11 shows zero `Hotline legacy caller Realtime event used.` entries.
- [~] Monitor legacy caller payload field usage if feasible: local log snapshot on 2026-05-11 showed 12 runtime legacy payload hits, all operator call-attempt `caller_id` fields on `POST /api/operator/call-attempts`, last seen at `2026-05-11 03:53:39`; operator call-attempt frontend now sends `citizen_id`, citizen/operator call-start frontends send `citizen_latitude` and `citizen_longitude`, and the 2026-05-11 04:02, 04:06, 04:09, 04:11, 04:14, production-served 04:17, and 04:21 live checks did not increase runtime legacy payload telemetry.
- [x] Confirm deployed clients have moved to citizen canonical routes and events: local WAMP live-browser calls now use `/api/realtime/admission/citizen` and publish/handle `citizen.*` canonical events; local reloaded-session validation is covered by the 2026-05-11 04:11 run, the production-served 04:17 run covered canonical call admission plus ready/hangup flow, the 04:21 run covered production terminal-status publish/apply for incident 202, and the 04:25 production tail confirmed post-call reconciliation ignored incident 203 after `currentIncidentId` cleared to `null`.
- [x] Inventory remaining caller compatibility aliases and split removal into staged batches: see `docs/citizen-protocol-decommission-inventory.md`.
- [x] Confirm Realtime shared service has moved to citizen canonical examples and fixtures: PBB Realtime confirmed on 2026-05-10 14:36:33 in the shared chat log that its Hotline reference-flow docs, browser app-event unit examples, generic unit fixture labels, project code examples, and media peer examples now use citizen/operator wording where not covering legacy compatibility; verification passed with `php artisan test tests\Unit\RealtimeGatewayTest.php tests\Unit\RealtimeMediaChunkDispatcherTest.php tests\Unit\RealtimeTokenValidatorTest.php` in `C:\wamp64\www\pbb\realtime`.
- [x] Confirm Helper shared service has moved to citizen canonical examples and fixtures: PBB Helper confirmed on 2026-05-11 04:36:42 in the shared chat log that it reviewed `C:\wamp64\www\hotline-helpers`, updated canonical device-selector wording from caller/operator to citizen/operator in `docs\ui-device-selector-proposal.md`, `docs\ui-device-selector-implementation-checklist.md`, and `demos\demo.device.selector.html`, verified the demo with a localhost/headless load check, and left only intentional Helper API/domain compatibility, historical sample data, generic programming docs, or unrelated local sample references.
- [x] Confirm caller-to-citizen refactor completion and installed PWA compatibility window have passed: installed PWA terminal-status validation passed with two terminal-status samples. The 2026-05-11 04:40 installed citizen PWA run for incident 204 used `/api/realtime/admission/citizen`, applied the terminal incident update at 20:40:48Z, and post-call reconciliation ignored the incident after `currentIncidentId` cleared to `null` at 20:40:53Z and again at 20:41:06Z. The 2026-05-11 04:42 installed citizen PWA run for incident 205 used `/api/realtime/admission/citizen`, applied the terminal incident update at 20:42:15Z, and post-call reconciliation ignored the incident after `currentIncidentId` cleared to `null` at 20:42:24Z.
- [x] Confirm historical data/report consumers are migrated or explicitly scoped out of alias cleanup: report/SITREP payloads already expose citizen aliases beside legacy caller keys, and the 2026-05-11 durable storage/history review classified database-backed caller names, historical media values, participant roles, and call outcomes as Batch 5 data-migration work that does not block Batch 2, Batch 3, or Batch 4 alias cleanup.
- [x] Remove caller event aliases: Batch 2 removed runtime `caller.*` Realtime event compatibility from the citizen/operator browser surfaces, deleted the `/api/realtime/legacy-caller-events` telemetry endpoint and controller, and updated the JS event contract to citizen-only event names.
- [x] Remove caller request-field aliases: Batch 3 removed legacy caller request-body fallback/logging from call-attempt, actual-citizen/intake, media registration, media assembly, and internal media chunk ingest paths; canonical requests now use `citizen_*`, `citizen_video`, and `peer_role: citizen|operator` while durable database columns and historical media values remain unchanged.
- [x] Remove caller route aliases: Batch 1 removed `/caller`, `/api/caller/*`, `/api/realtime/admission/caller`, caller-named operator route aliases, and the legacy caller route telemetry middleware.
- [x] Remove caller PWA assets only after compatibility window: Batch 4 removed `caller.webmanifest`, `caller-sw.js`, `/caller` service-worker fallbacks, and the browser PWA surface alias.
- [~] Remove caller database columns/tables after staged citizen migration and final decommission approval: Batch 5A now makes `citizen_id` the active runtime identity column while keeping `caller_id` synchronized for rollback; Batch 5B now adds citizen-named incident detail/location columns and dual-write/read fallback; Batch 5C now introduces `incident_citizen_locations` with mirrored writes and preferred reads; Batch 5D+ media/history, report-key, and final-drop work remains pending.

Current decommission gate:
- Do not remove caller-shaped database columns, historical media values, participant roles, outcomes, or legacy report keys in the alias-removal PRs; those are Batch 5 data-migration work. Batch 5A has switched public-user identity reads to `citizen_id` without removing the synchronized `caller_id` rollback columns, Batch 5B has added citizen-named incident detail/location columns without dropping the caller-shaped rollback columns, and Batch 5C has introduced citizen-named location history while keeping `incident_caller_locations` populated.
- The local and production-served readiness passes proved current `/citizen` flows are canonical, including live call setup, hangup/reconnect handling, operator offline recovery, and post-call status propagation. Legacy route and payload telemetry stayed flat through the live validation window. Realtime shared-service, Helper shared-service, installed PWA terminal-status, and durable storage/history scope confirmations are complete. Batch 1 route alias removal, Batch 2 Realtime event alias removal, Batch 3 request-field alias removal, and Batch 4 PWA asset removal are complete.

## Open Decisions Tracker

- [x] Decide physical DB rename vs citizen accessors over existing caller columns: physical rename through staged migration.
- [x] Decide media protocol rename strategy: migrate caller protocol/media values to citizen values.
- [x] Decide call outcome rename strategy: canonical new values use citizen terminology while legacy caller outcome values remain readable during compatibility.
- [x] Decide legacy caller compatibility duration: until the caller-to-citizen refactor is complete.
- [x] Decide whether telemetry is required for legacy usage: yes.
- [x] Decide whether this repo owns all telemetry for legacy usage or whether Realtime/Helper should also provide service-local telemetry: Hotline owns app-visible legacy route/event/payload telemetry for the current compatibility window; Realtime/Helper only need service-local telemetry if they later accept or translate legacy caller contracts themselves.
- [x] Decide which external repos need synchronized PRs before Hotline switches canonical behavior: no blocking external PR is required for current Hotline canonical behavior; Realtime docs/fixtures should be synchronized before final caller event decommission, and Helper needs no upstream change for this Hotline-owned terminology slice.
