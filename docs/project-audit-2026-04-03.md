# Hotline Project Audit

Date: 2026-04-03

Scope:
- Laravel 12.47.0 application under `C:\wamp64\www\hotline`
- Runtime verified with PHP 8.2.29
- Database connection verified against MySQL `hotline`
- Live route inventory: 116 routes
- Migrations present: 39
- First-party Eloquent models: 21

## Executive Summary

This project is a Laravel 12 incident-response application with four main personas:
- `user`: caller / citizen
- `operator`: first-line hotline operator
- `command`: command-center oversight
- `administrator`: system setup, reporting, reference data, API token management

The core domain is coherent and already beyond scaffold stage:
- incidents and incident typing
- call sessions and media ingestion
- operator transfer workflow
- team dispatch and resource allocation
- activity logging
- Sanctum-backed read/write API

The main weaknesses are not in missing features. They are in boundary control and maintainability:
- incident endpoints under the generic `auth` group are under-protected
- public ingest endpoints rely on a single shared header secret
- the homepage and bootstrap flow hard-depend on the `settings` table, including in test runs
- the shipped test suite is mostly scaffold-era coverage and is now out of sync with the app

## Architecture Snapshot

### Backend stack
- Framework: Laravel 12
- Auth: session auth for web, Sanctum for API
- Realtime: Laravel Reverb / broadcast events
- Queue/cache/session persistence configured to database in local env
- Storage: `public` disk for photos/audio/video artifacts

### Main bounded areas
- Caller experience: `HomeController`, `CallController`, `IncidentMessageController`
- Operator console: `OperatorController`, `IncidentController`
- Command console: `CommandController`
- Admin setup/reporting: `AdminController`, `AdminIncidentController`, `AdminTeamController`, `AdminResourceController`
- External API: `app/Http/Controllers/Api/V1/*`

### Live data footprint
The `hotline` schema currently contains active data, not just empty scaffolding:
- 29 incidents
- 54 call sessions
- 95 media rows
- 43 team assignments
- 460 activity logs
- 7 users

## Findings

### 1. High: incident read/write endpoints are reachable by any authenticated user

Evidence:
- Generic authenticated routes expose incident show/update/detail/resource operations in [routes/web.php](C:/wamp64/www/hotline/routes/web.php#L62) lines 62-91, especially lines 82-86.
- `IncidentController@show` does not enforce caller/operator/role ownership before returning full incident payload in [IncidentController.php](C:/wamp64/www/hotline/app/Http/Controllers/IncidentController.php#L241) lines 241-305.
- `IncidentController@update` and `applyIncidentUpdate` accept incident mutation without a role gate in [IncidentController.php](C:/wamp64/www/hotline/app/Http/Controllers/IncidentController.php#L57) lines 57-179 and [IncidentController.php](C:/wamp64/www/hotline/app/Http/Controllers/IncidentController.php#L224) lines 224-239.
- `IncidentController@updateResources` also mutates incident-linked data without authorization checks in [IncidentController.php](C:/wamp64/www/hotline/app/Http/Controllers/IncidentController.php#L755) lines 755-801.

Impact:
- Any logged-in `user` can request another incident by ID.
- Any logged-in `user` can potentially alter status, location, details, and incident types on incidents they do not own.
- This is a cross-tenant data exposure and integrity risk.

Recommended action:
- Add explicit authorization in every incident endpoint.
- At minimum enforce one of:
  - caller owns the incident
  - assigned operator owns the incident
  - command role
  - administrator role
- Prefer Laravel policies so web and API share the same authorization logic.

### 2. Medium-High: websocket finalize endpoints are public routes guarded only by a shared token header

Evidence:
- Public routes exist outside `auth` middleware in [routes/web.php](C:/wamp64/www/hotline/routes/web.php#L50) lines 50-53.
- Protection in `CallController@finalizeRecordingFromWs` is only `X-WS-Token` equality against `config('recordings.ws_token')` in [CallController.php](C:/wamp64/www/hotline/app/Http/Controllers/CallController.php#L759) around line 759.
- Protection in `IncidentMessageController@finalizePhotoFromWs` is the same pattern in [IncidentMessageController.php](C:/wamp64/www/hotline/app/Http/Controllers/IncidentMessageController.php#L165) lines 165-177.

Impact:
- One leaked token grants upload finalization authority for recording and photo assembly.
- No actor-level signature, expiry, nonce, or request-scoped MAC is enforced.
- No route throttling is visible on these endpoints.

Recommended action:
- Replace the shared static token with signed short-lived upload intents.
- Bind finalize requests to call ID, incident ID, role, upload ID, and expiry.
- Add throttling and structured audit logging for failed attempts.

### 3. Medium: public homepage/bootstrap path hard-depends on `settings` table and fails in SQLite test environment

Evidence:
- `HomeController@index`, `about`, and `bootstrap` directly query `Setting` in [HomeController.php](C:/wamp64/www/hotline/app/Http/Controllers/HomeController.php#L31) lines 31-33, 52-54, and 64-66.
- PHPUnit is configured to use in-memory SQLite in [phpunit.xml](C:/wamp64/www/hotline/phpunit.xml#L20) lines 20-33.
- `php artisan test` fails with `no such table: settings` when hitting `/`.

Impact:
- The public homepage is not resilient when seed data is absent.
- Feature tests that hit `/` fail unless migrations and seeders are aligned.
- This makes regression testing expensive and brittle.

Recommended action:
- Centralize settings access behind a service that supplies defaults when the table is unavailable or keys are missing.
- Seed required settings in test setup or migrate the test DB before routes using them are exercised.

### 4. Medium: API `incident_type_id` filtering is logically incorrect

Evidence:
- API filtering uses `detailEntries` instead of the canonical incident-type pivot in [Api IncidentController.php](C:/wamp64/www/hotline/app/Http/Controllers/Api/V1/IncidentController.php#L35) lines 35-40.
- The authoritative relation is `Incident::incidentTypes()` via `incident_incident_type`, not `detailEntries`.

Impact:
- Incidents tagged with a type but lacking detail rows for that type will be omitted from API results.
- API consumers can receive incomplete or misleading incident lists.

Recommended action:
- Filter via `whereHas('incidentTypes', ...)`.
- Optionally include filtered detail entries separately if needed for payload trimming.

### 5. Medium: auth flow and scaffold tests have drifted apart

Evidence:
- `/login` intentionally redirects to `/?auth=login` in [AuthenticatedSessionController.php](C:/wamp64/www/hotline/app/Http/Controllers/Auth/AuthenticatedSessionController.php#L22) lines 22-25.
- logout redirects to role-aware landing pages with `?auth=login` in [AuthenticatedSessionController.php](C:/wamp64/www/hotline/app/Http/Controllers/Auth/AuthenticatedSessionController.php#L82) lines 82-116.
- registration routes are absent from [routes/auth.php](C:/wamp64/www/hotline/routes/auth.php#L13) lines 13-53.
- scaffold tests still expect `/register` and default Breeze redirects in [RegistrationTest.php](C:/wamp64/www/hotline/tests/Feature/Auth/RegistrationTest.php#L12) lines 12-30.

Impact:
- 7 current test failures are expected from route/redirect drift, not from random breakage.
- The suite no longer communicates true product health.

Recommended action:
- Either restore registration routes if self-service registration is intended, or remove the dead flow and replace tests with current expected behavior.
- Update auth tests to the customized UX contract.

### 6. Medium: custom domain surface has almost no coverage

Evidence:
- Only Breeze-style auth/profile/example tests are present under `tests/`.
- No feature tests exist for call lifecycle, incident transfer, media upload, team dispatch, activity logging, or API tokens.

Impact:
- The most critical workflows are the least protected against regression.
- Refactors in controllers with heavy branching are high-risk.

Recommended action:
- Add feature coverage for:
  - caller call start / operator answer / call end
  - incident transfer accept/decline/cancel
  - team assignment state transitions
  - API token authorization
  - websocket finalize endpoints

## Test Status

Command run:
- `C:\wamp64\bin\php\php8.2.29\php.exe artisan test`

Result:
- 18 passed
- 7 failed

Observed failure groups:
- Auth redirect drift: `/login`, `/logout`, profile redirect expectations
- Registration flow drift: `/register` route absent
- Root route failure in test DB: missing `settings` table under SQLite

## Database Audit Notes

### Strengths
- Foreign keys are present across the main domain tables.
- Domain modeling is normalized enough for incident typing, resources, and team dispatch.
- The migration trail shows iterative evolution rather than a single brittle dump.

### Weak points
- `users.current_call_session_id` and `users.current_incident_id` are indexed but not constrained with foreign keys.
- `call_sessions.forwarded_from_operator_id` is indexed but not constrained.
- JSON fields are used appropriately, but some operational behavior now depends on values inside JSON rather than stronger schema constraints.

## Recommended Next Priority Order

1. Lock down incident authorization with policies and role-aware checks.
2. Harden websocket finalize endpoints with signed, expiring upload authorization.
3. Stabilize settings access for tests and cold environments.
4. Fix API incident-type filtering.
5. Rewrite the test suite around real product behavior, not Breeze defaults.

## Overall Assessment

The project is functionally substantial and the core data model is serviceable. The current risk is mostly boundary safety and operational maintainability, not lack of business logic. If the authorization and test gaps are addressed, this codebase can move from fragile-custom to reliably maintainable without a major redesign.
