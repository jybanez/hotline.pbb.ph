# Citizen Durable Storage Migration Plan

Date: 2026-05-11

Status: Batch 5 planning inventory after route, Realtime event, request-field, and PWA alias removal.

This plan covers caller-named database columns, table names, historical enum values, and report payload keys. These names are not temporary browser/API aliases. They are persisted data contracts and need staged migrations with rollback points.

## Current State

Completed compatibility groundwork:

- `citizen_id` columns already exist beside `caller_id` on `incidents`, `call_attempts`, `call_sessions`, and `incident_caller_locations`.
- Existing rows were backfilled from `caller_id` to `citizen_id`.
- Runtime request validation now rejects legacy caller request fields on the live call/media write paths.
- Runtime responses expose citizen-facing aliases for current public-user identity, location, media role, and report fields.

Remaining durable caller storage/history names:

- Identity columns:
  - `incidents.caller_id`
  - `call_attempts.caller_id`
  - `call_sessions.caller_id`
  - `incident_caller_locations.caller_id`
- Incident detail columns:
  - `incidents.actual_caller_name`
  - `incidents.actual_caller_relationship`
  - `incidents.caller_location_accuracy`
  - `incidents.caller_altitude`
  - `incidents.caller_altitude_accuracy`
  - `incidents.caller_heading`
  - `incidents.caller_heading_source`
  - `incidents.caller_location_captured_at`
- Location history table:
  - `incident_caller_locations`
- Historical protocol values:
  - `media.type = caller_video`
  - `media.peer_role = caller`
  - `call_participants.participant_role = caller`
  - `call_attempts.outcome = cancelled_by_caller`
  - `call_sessions.outcome = ended_by_caller`
- Compatibility output/report keys:
  - `caller_id`, `caller`, `actual_caller_*`, `caller_location`
  - `caller_locations`, `missing_caller_location_count`, `caller_phone_numbers`, `callers_assisted`
- Configuration compatibility:
  - `HOTLINE_CALLER_SESSION_LIFETIME`
  - `settings.caller_relationships`

## Non-Goals For The First Batch 5 PR

Do not drop columns or legacy enum cases in the first storage PR.

Do not rewrite old migrations unless this repo intentionally squashes or refreshes its baseline. Add forward migrations instead.

Do not remove legacy report/SITREP keys until any external consumers have a notification window or the payload version changes.

## Proposed Sequence

### Batch 5A: Switch Runtime Reads And Writes To Existing Citizen Columns

Status: Complete in code on 2026-05-11; keep production observation active through the next live validation window.

Goal: make `citizen_id` the active application column while keeping `caller_id` synchronized for rollback.

Changes:

- [x] Update models so `citizen()` relationships use `citizen_id`.
- [x] Keep `caller()` relationships as deprecated aliases pointing to `caller_id` during the transition.
- [x] Update query filters from `caller_id` to `citizen_id` where the code is identifying the public user.
- [x] On new writes, set both `citizen_id` and `caller_id` to the same user id.
- [x] Add drift tests proving `citizen_id` is populated and used for newly created incidents, call attempts, call sessions, and incident location history.

Rollback:

- Because `caller_id` remains populated, revert code to the previous reads/writes without data loss.

### Batch 5B: Add Citizen-Named Detail Columns

Goal: stop writing public-user details into `actual_caller_*` and `caller_location_*`.

Changes:

- Add nullable columns:
  - `incidents.actual_citizen_name`
  - `incidents.actual_citizen_relationship`
  - `incidents.citizen_location_accuracy`
  - `incidents.citizen_altitude`
  - `incidents.citizen_altitude_accuracy`
  - `incidents.citizen_heading`
  - `incidents.citizen_heading_source`
  - `incidents.citizen_location_captured_at`
- Backfill from the current caller-named columns.
- Update writes to populate both citizen-named and caller-named detail columns.
- Update reads to prefer citizen-named columns with caller-named fallback.

Rollback:

- Caller-named columns remain synchronized.

### Batch 5C: Introduce `incident_citizen_locations`

Goal: move location history to a citizen-named table without losing old history.

Changes:

- Create `incident_citizen_locations` with the same shape as `incident_caller_locations`, using `citizen_id`.
- Backfill from `incident_caller_locations`.
- Update new writes to write both tables for one release, or write citizen table first and mirror caller table in the same transaction.
- Update reads to prefer `incident_citizen_locations`.
- Add count/parity checks for incident id, session id, coordinates, timestamps, and source.

Rollback:

- Keep `incident_caller_locations` populated until after production verification.

### Batch 5D: Migrate Historical Protocol Values

Goal: convert stored media, participant, and outcome values to citizen terminology.

Changes:

- Backfill:
  - `media.type: caller_video -> citizen_video`
  - `media.peer_role: caller -> citizen`
  - `call_participants.participant_role: caller -> citizen`
  - `call_attempts.outcome: cancelled_by_caller -> cancelled_by_citizen`
  - `call_sessions.outcome: ended_by_caller -> ended_by_citizen`
- Keep `MediaContractNormalizer` and `CallOutcome::canonical()` until after all consumers and tests no longer need legacy values.
- Add idempotent migration checks so re-running the migration is safe.

Rollback:

- The value rewrite can be reversed for the known value pairs if needed.

### Batch 5E: Remove Legacy Output Keys And Deprecated Accessors

Goal: remove caller-shaped response/report aliases after a consumer notification window.

Changes:

- Remove legacy response aliases from `IncidentPayloadBuilder` and command/citizen/operator payloads.
- Remove `caller_locations`, `missing_caller_location_count`, `caller_phone_numbers`, and `callers_assisted` only after the report payload contract is versioned or consumers are confirmed migrated.
- Remove deprecated `caller()` relationships or keep them only as internal historical accessors with no API exposure.
- Remove `HOTLINE_CALLER_SESSION_LIFETIME` fallback and rename `settings.caller_relationships` to a citizen-named key if configuration consumers are migrated.

Rollback:

- Restore payload aliases if external consumers break; no data rollback needed.

### Batch 5F: Drop Caller Columns And Tables

Goal: destructive cleanup after production has run cleanly on citizen-named storage.

Prerequisites:

- `citizen_id` and citizen-named detail columns are non-null where business rules require them.
- `incident_citizen_locations` parity with old location history has been verified.
- Legacy protocol value counts are zero:
  - `media.type = caller_video`
  - `media.peer_role = caller`
  - `call_participants.participant_role = caller`
  - `call_attempts.outcome = cancelled_by_caller`
  - `call_sessions.outcome = ended_by_caller`
- Legacy response/report aliases have completed their consumer window.

Changes:

- Drop `caller_id` columns from the four public-user tables.
- Drop `actual_caller_*` and `caller_location_*` incident columns.
- Drop `incident_caller_locations`.
- Remove deprecated enum cases only if no persisted rows can hydrate them.

Rollback:

- Requires restoring from backup or running reverse migrations that recreate and backfill from citizen-named storage. Treat this as a production change with an explicit database backup and maintenance window.

## Verification Gates

Before 5A:

- Confirm all four `citizen_id` columns have no nulls where `caller_id` is present.
- Run the full PHP suite and JS contract tests.

Before 5D:

- Count legacy protocol values and record them in the PR.
- Confirm current live calls write only citizen protocol values.

Before 5F:

- Run parity SQL for identity columns and location history.
- Run full test suite.
- Run a live smoke test: new call, reconnect, citizen hangup, operator hangup, terminal status update.
- Capture backup/rollback steps in the release notes.
