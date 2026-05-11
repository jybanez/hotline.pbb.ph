# Citizen Storage 5F Release Checklist

Batch 5F removes caller-shaped database storage after the citizen migration. Treat this as a destructive schema release even though the reverse migration can recreate caller-shaped columns and backfill them from citizen-named storage.

Branch: `codex/citizen-live-call-readiness`

Implemented commit: `a874d3c Drop legacy caller storage`

## Pre-Deploy

- Confirm there are no active live calls and schedule a short maintenance window.
- Confirm the deployed application code and migration are released together. Do not run the 5F migration against an older caller-storage build.
- Take a database backup before running the migration.
- Confirm the PHP runtime is PHP 8.2 or newer. Local verification used `C:\wamp64\bin\php\php8.2.29\php.exe`.

Example backup command:

```powershell
mysqldump --single-transaction --routines --triggers --events --default-character-set=utf8mb4 -u <user> -p <database> > backups\hotline-before-5f-YYYYMMDDHHMM.sql
```

Use the production MySQL binary path if `mysqldump` is not on `PATH`.

## Preflight SQL

Run these checks before `php artisan migrate --force`.

```sql
SELECT COUNT(*) AS incidents_missing_citizen_id
FROM incidents
WHERE citizen_id IS NULL;

SELECT COUNT(*) AS call_attempts_missing_citizen_id
FROM call_attempts
WHERE citizen_id IS NULL;

SELECT COUNT(*) AS call_sessions_missing_citizen_id
FROM call_sessions
WHERE citizen_id IS NULL;

SELECT COUNT(*) AS citizen_location_rows
FROM incident_citizen_locations;

SELECT type, COUNT(*) AS total
FROM media
WHERE type = 'caller_video'
GROUP BY type;

SELECT peer_role, COUNT(*) AS total
FROM media
WHERE peer_role = 'caller'
GROUP BY peer_role;

SELECT participant_role, COUNT(*) AS total
FROM call_participants
WHERE participant_role = 'caller'
GROUP BY participant_role;

SELECT outcome, COUNT(*) AS total
FROM call_attempts
WHERE outcome = 'cancelled_by_caller'
GROUP BY outcome;

SELECT outcome, COUNT(*) AS total
FROM call_sessions
WHERE outcome = 'ended_by_caller'
GROUP BY outcome;
```

Expected results:

- `citizen_id` counts are zero where production records are expected to be assigned to a citizen.
- Legacy protocol value queries return no rows.
- `incident_citizen_locations` contains the retained location history.

## Deploy

1. Put the app into maintenance mode.
2. Deploy the code containing `a874d3c` or a later commit on the same branch.
3. Run `php artisan migrate --force`.
4. Run `php artisan migrate:status --path=database/migrations/2026_05_11_000004_drop_caller_storage_columns.php` and confirm the migration is `Ran`.
5. Clear config, route, and view caches if production caches are enabled.
6. Build and publish frontend assets if the deployment process does not already do this.
7. Bring the app out of maintenance mode.

## Smoke Test

- Start a new citizen call and answer it from the operator surface.
- Confirm both sides reach connected state and both streams appear.
- End one call from the citizen side and confirm the operator receives the hangup-complete path.
- Start another call, end it from the operator side, then set the incident to `Resolved` or `Discarded`.
- Confirm the citizen receives the terminal incident update through Realtime and clears the active incident without repeated `/api/citizen/home` reconciliation calls.
- Confirm the operator chat thread remains usable after call end.
- Confirm a stale citizen tab can reauthenticate before call admission or exits cleanly instead of leaving both sides stuck in connecting state.

## Rollback

If the issue is found immediately after migration and before new production writes need to be preserved:

1. Put the app into maintenance mode.
2. Deploy the previous application build.
3. Run `php artisan migrate:rollback --step=1 --force`.
4. Clear production caches.
5. Bring the app out of maintenance mode.

If production writes occurred after the migration or data integrity is uncertain:

1. Put the app into maintenance mode.
2. Restore the pre-5F database backup.
3. Deploy the previous application build.
4. Clear production caches.
5. Bring the app out of maintenance mode.

## Post-Deploy Monitoring

Watch server and browser logs for these signals:

- SQL errors for missing caller columns or `incident_caller_locations`.
- Runtime payloads containing `caller_id`, `actual_caller_*`, or `caller_location_*`.
- `/api/realtime/admission/citizen` returning `401` without a clean reauthentication path.
- Repeated `/api/citizen/home` calls after terminal `hotline.incident.updated` events.
- Operator chat rendering blank or malformed after call end.
- Media finalizer `409 Conflict` responses that do not settle after retry cleanup.
