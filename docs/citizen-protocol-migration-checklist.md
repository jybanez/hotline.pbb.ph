# Citizen Protocol Migration Checklist

Date: 2026-05-10

Status: Working tracker for Phase 2 caller-to-citizen migration

Related plan:
- [Citizen Protocol Migration Plan](citizen-protocol-migration-plan.md)

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
- [ ] Review caller-to-citizen plan with Hotline owner.
- [ ] Review Realtime-facing event and admission changes with Realtime service owner.
- [ ] Review Helper-facing adapter and UI changes with Helper service owner.
- [ ] Confirm whether the canonical public-user term is always `citizen` across PBB ecosystem docs.
- [ ] Decide support window for legacy `caller` routes, fields, events, and PWA assets.
- [ ] Decide whether telemetry is required before decommissioning legacy caller contracts.

## Contract Inventory Tasks

- [ ] Inventory all remaining `caller` strings in `app/`.
- [ ] Inventory all remaining `caller` strings in `routes/`.
- [ ] Inventory all remaining `caller` strings in `resources/`.
- [ ] Inventory all remaining `caller` strings in `public/`.
- [ ] Inventory all remaining `caller` strings in `database/`.
- [ ] Inventory all remaining `caller` strings in `config/`.
- [ ] Inventory all remaining `caller` strings in `tests/`.
- [ ] Inventory all remaining `caller` strings in `docs/`.
- [ ] Classify each remaining `caller` usage as user-facing copy, internal code, API contract, DB contract, Realtime contract, Helper contract, report output, test fixture, or historical documentation.
- [ ] Mark usages that should intentionally remain `caller` for compatibility or historical accuracy.

## Roles and Auth

- [x] Canonical citizen route access is available.
- [x] Legacy caller route access still works for compatible users.
- [x] Session lifetime supports `HOTLINE_CITIZEN_SESSION_LIFETIME`.
- [x] Session lifetime keeps `HOTLINE_CALLER_SESSION_LIFETIME` fallback compatibility.
- [ ] Confirm all new seed users use `citizen`.
- [ ] Confirm factories and test helpers default to `citizen` where appropriate.
- [ ] Add migration or mapping plan for persisted users with role value `caller`.
- [ ] Add deprecation note for the legacy `caller` role value.
- [ ] Add tests for any role migration or alias behavior introduced in Phase 2.

## Web Routes and PWA

- [x] `/citizen` is canonical.
- [x] `/caller` is legacy-compatible.
- [x] `/citizen/offline` exists.
- [x] `/caller/offline` exists.
- [ ] Add or verify `citizen.webmanifest`.
- [ ] Add or verify `citizen-sw.js`.
- [ ] Keep `caller.webmanifest` available for installed legacy PWAs.
- [ ] Keep `caller-sw.js` available for installed legacy PWAs.
- [ ] Verify service-worker scope for `/citizen`.
- [ ] Verify offline behavior for `/citizen/offline`.
- [ ] Verify legacy installed caller PWA opens or redirects safely.
- [ ] Add PWA cache-name migration notes if cache keys include caller terminology.

## Public-User API Routes

- [x] `/api/citizen/*` prefix exists.
- [x] `/api/caller/*` prefix remains available.
- [ ] Confirm all citizen surface frontend calls use `/api/citizen/*` where possible.
- [ ] Add tests proving `/api/citizen/*` and `/api/caller/*` route pairs return equivalent results during compatibility.
- [ ] Add legacy caller API usage logging if telemetry is required.
- [ ] Document deprecation timeline for `/api/caller/*`.

## Operator API Routes

- [ ] Add canonical citizen alias for `/api/operator/incidents/{incident}/actual-caller`.
- [ ] Add canonical citizen alias for `/api/operator/incidents/{incident}/caller-address`.
- [ ] Add canonical citizen alias for `/api/operator/incidents/{incident}/caller-location`.
- [ ] Add canonical citizen alias for `/api/operator/incidents/{incident}/caller-locations`.
- [ ] Add canonical citizen alias for `/api/operator/call-attempt-operator-attempts/{attempt}/caller-cancel`.
- [ ] Keep all caller-named operator routes as compatibility aliases.
- [ ] Update operator frontend calls to use citizen-named routes where appropriate.
- [ ] Add route tests for each citizen alias.
- [ ] Add route tests proving caller aliases remain compatible.
- [ ] Add legacy caller operator route usage logging if telemetry is required.

## API Payloads

- [ ] Add canonical `citizen` object beside legacy `caller` object in incident payloads.
- [ ] Add canonical `citizen_id` beside legacy `caller_id` where payloads expose public-user identity.
- [ ] Add canonical `actual_citizen_name` request/response support.
- [ ] Add canonical `actual_citizen_relationship` request/response support.
- [ ] Add canonical `citizen_location` beside legacy `caller_location`.
- [ ] Add canonical `citizen_locations` beside legacy `caller_locations`.
- [ ] Add canonical `missing_citizen_location_count` beside legacy `missing_caller_location_count`.
- [ ] Add canonical `citizen_phone_numbers` beside legacy `caller_phone_numbers`.
- [ ] Add canonical `citizens_assisted` beside legacy `callers_assisted`.
- [ ] Accept both citizen and caller request fields during compatibility.
- [ ] Define conflict behavior when both citizen and caller request fields are provided.
- [ ] Add serialization tests for citizen canonical fields.
- [ ] Add compatibility tests for legacy caller fields.

## Database and Models

- [ ] Decide whether database columns will be physically renamed or wrapped by citizen-facing accessors.
- [ ] Decide whether `incident_caller_locations` should eventually become `incident_citizen_locations`.
- [ ] Add model accessors or DTO layer for citizen-facing reads.
- [ ] Add citizen-facing relationship names where useful, such as `Incident::citizen`.
- [ ] Keep caller-facing relationship names during compatibility.
- [ ] If additive columns are approved, add nullable citizen columns first.
- [ ] If additive columns are approved, backfill citizen columns from caller columns.
- [ ] If additive columns are approved, switch writes after read compatibility exists.
- [ ] If additive columns are approved, add rollback-safe migrations.
- [ ] Defer destructive caller column/table removal until final decommission.
- [ ] Update `BlockedDeleteInspectorService` labels to citizen-facing wording without breaking table checks.

## Realtime Admission

- [x] `/api/realtime/admission/citizen` is canonical.
- [x] `/api/realtime/admission/caller` remains compatible.
- [x] `RealtimeAdmissionService::forCitizen()` exists.
- [x] Legacy caller admission method remains as compatibility wrapper.
- [ ] Confirm Realtime shared service documentation uses citizen admission path.
- [ ] Add Realtime-side fixture/example updates if needed.
- [ ] Add telemetry for legacy caller admission path if required.

## Realtime Events

- [ ] Define citizen equivalent for `caller.operator.available.request`.
- [ ] Define citizen equivalent for `caller.operator.available.response`.
- [ ] Define citizen equivalent for `caller.call.request`.
- [ ] Define citizen equivalent for `caller.call.ringing`.
- [ ] Define citizen equivalent for `caller.call.cancel`.
- [ ] Define citizen equivalent for `caller.call.cancelled`.
- [ ] Define citizen equivalent for `caller.call.declined`.
- [ ] Define citizen equivalent for `caller.call.answered`.
- [ ] Define citizen equivalent for `caller.call.ready`.
- [ ] Define citizen equivalent for `caller.location.updated`.
- [ ] Define citizen equivalents for `caller.reconnect.*`.
- [ ] Update citizen client to publish canonical citizen events.
- [ ] Update citizen client to listen for both citizen and caller events.
- [ ] Update operator client to publish canonical citizen events where Hotline owns publication.
- [ ] Update operator client to listen for both citizen and caller events.
- [ ] Update Realtime payload fields from `caller_id` to include `citizen_id`.
- [ ] Update Realtime payload fields from `caller_name` to include `citizen_name`.
- [ ] Add tests for canonical citizen event flow.
- [ ] Add tests for legacy caller event compatibility.
- [ ] Coordinate deprecation date with Realtime service owner.

## Helper Integration

- [ ] Rename Hotline-owned `callerBootstrapAdapter()` to a citizen-named adapter.
- [ ] Rename Hotline-owned `startCallAdapter()` if its caller meaning is public-user specific.
- [ ] Rename Hotline-owned `reconnectCallAdapter()` if its caller meaning is public-user specific.
- [ ] Keep caller-named adapter aliases while imports or docs still depend on them.
- [ ] Update Helper-facing adapter payloads to emit citizen canonical fields.
- [ ] Update Helper-facing adapter payloads to accept legacy caller fields.
- [ ] Update Helper docs or proposals that still describe the public user as caller.
- [ ] Refresh vendored Helper copy if upstream Helper changes are needed.
- [ ] Smoke-test Helper-backed citizen chat, media viewer, forms, and call UI.

## Media and Call Session Contracts

- [ ] Decide whether `caller_video` should become `citizen_video`.
- [ ] Decide whether `caller-audio` keys should become `citizen-audio`.
- [ ] Decide whether `caller-cam-*` segment keys should become `citizen-cam-*`.
- [ ] Decide whether `peer_role = caller` should become `peer_role = citizen`.
- [ ] Decide whether `participant_role = caller` should become `participant_role = citizen`.
- [ ] Decide whether outcomes like `cancelled_by_caller` and `ended_by_caller` should remain stable analytics values.
- [ ] If media values change, accept both caller and citizen media values during compatibility.
- [ ] If participant values change, add tests for old call sessions and new call sessions.
- [ ] Verify media assembly still works with legacy records.
- [ ] Verify operator media playback still filters correctly.
- [ ] Verify citizen media visibility rules still hold.

## Reports and SITREP

- [ ] Add citizen-facing report label for callers assisted.
- [ ] Add `citizens_assisted` beside `callers_assisted` if report payloads are consumed externally.
- [ ] Add `citizen_locations` beside `caller_locations` if report payloads are consumed externally.
- [ ] Add `missing_citizen_location_count` beside `missing_caller_location_count`.
- [ ] Add `citizen_phone_numbers` redaction key beside `caller_phone_numbers` if needed.
- [ ] Update generated SITREP prose from caller to citizen where user-facing.
- [ ] Keep historical or schema-specific caller terms where required.
- [ ] Add SITREP tests for citizen-facing output.

## Docs and OpenAPI

- [ ] Update `docs/pbb-hotline-beta-contracts.md` roles from caller to citizen after behavior exists.
- [ ] Update `docs/pbb-hotline-beta-realtime-spec.md` with citizen events and legacy aliases.
- [ ] Update `docs/pbb-hotline-beta-api-inventory.md` with citizen routes and fields.
- [ ] Update `docs/pbb-hotline-beta-schema-draft.md` with migration notes.
- [ ] Update `docs/hotline-helper-mapping.md` from caller surface to citizen surface.
- [ ] Update `docs/openapi/pbb-hotline-beta.yaml` with citizen API contracts.
- [ ] Mark old caller docs as historical or deprecated instead of silently rewriting old architecture records.

## Testing Gates

- [ ] Run focused routing tests after route alias changes.
- [ ] Run focused auth/session tests after role changes.
- [ ] Run focused Realtime admission tests after admission changes.
- [ ] Run focused Realtime client tests after event changes.
- [ ] Run focused operator workbench tests after API/payload changes.
- [ ] Run focused citizen surface tests after frontend API changes.
- [ ] Run focused media tests after media contract changes.
- [ ] Run focused SITREP tests after report changes.
- [ ] Run PWA/offline smoke test after manifest/service-worker changes.
- [ ] Run Helper integration smoke test after Helper adapter changes.
- [ ] Run full test suite before merging each runtime PR.

## Deployment and Decommission

- [ ] Deploy citizen compatibility additions before removing caller contracts.
- [ ] Monitor legacy caller route usage.
- [ ] Monitor legacy caller Realtime event usage.
- [ ] Monitor legacy caller payload field usage if feasible.
- [ ] Confirm deployed clients have moved to citizen canonical routes and events.
- [ ] Confirm Realtime shared service has moved to citizen canonical examples and fixtures.
- [ ] Confirm Helper shared service has moved to citizen canonical examples and fixtures.
- [ ] Confirm installed PWA compatibility window has passed.
- [ ] Confirm historical data/report consumers are migrated.
- [ ] Remove caller route aliases.
- [ ] Remove caller event aliases.
- [ ] Remove caller request-field aliases.
- [ ] Remove caller PWA assets only after compatibility window.
- [ ] Remove or rename caller database columns/tables only after explicit approval.

## Open Decisions Tracker

- [ ] Decide physical DB rename vs citizen accessors over existing caller columns.
- [ ] Decide media protocol rename strategy.
- [ ] Decide call outcome rename strategy.
- [ ] Decide legacy caller compatibility duration.
- [ ] Decide whether this repo owns telemetry for legacy usage or whether Realtime/Helper should provide it.
- [ ] Decide which external repos need synchronized PRs before Hotline switches canonical behavior.
