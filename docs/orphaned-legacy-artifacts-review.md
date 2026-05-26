# Orphaned And Legacy Artifact Review

Date: 2026-05-26

Purpose:
- document cleanup candidates before deleting anything
- separate verified orphan candidates from active legacy compatibility
- give Jojo a review list for confirmation

No code or file cleanup has been performed from this list yet.

## Verification Snapshot

Commands used for this first pass:
- `git remote get-url origin`
- `php artisan route:list`
- `php artisan list app`
- targeted `rg` searches for route/controller/model/view/static-asset references
- targeted checks against `vite.config.js`, Blade `@vite(...)` entries, and tracked files

Current active route table:
- 136 Laravel routes
- no active `/api/hubs`, `/api/geodata`, `/hubs`, `/geodata`, or Swagger Hubs route
- no active `/api/caller/*` or `/caller` route

## Review Status Legend

- `remove candidate`: appears unused by active routes/build/runtime and can likely be removed after confirmation
- `needs decision`: may be legacy or broken, but has a live reference such as a scheduler entry
- `keep for now`: still intentionally active or documented as a temporary compatibility guard
- `rename/refactor later`: active code path still uses legacy names internally

## Cleanup Candidates

| Cleanup Status | Review Status | Artifact(s) | Evidence | Proposed Action |
| --- | --- | --- | --- | --- |
| removed 2026-05-26 | remove candidate | `app/Http/Controllers/Api/AuthController.php` | No active route references it. Session routes now use controllers under `app/Http/Controllers/Api/Session`. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `app/Http/Controllers/Api/BootstrapController.php` | Active `/api/bootstrap` uses `App\Http\Controllers\Api\Public\BootstrapController`; this older controller still returns `PBB - HQ` payload shape. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `app/Http/Controllers/Api/UserController.php` | No active route references it. It validates old roles `admin`, `manager`, `viewer`, which do not match Hotline roles. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `app/Http/Controllers/Api/SettingsController.php` | No active route references it. Admin settings now use `App\Http\Controllers\Api\Admin\SettingsController`. | Removed after approval. |
| pending | remove candidate | `app/Http/Controllers/Api/WorkspaceController.php` and `config/services.php` `workspace.access_token` | No active route references `WorkspaceController`; only broad workspace strings remain in active Realtime room names/UI labels. | Remove controller and config key after confirming Workspace app-access endpoint is not required for Hotline onboarding. |
| removed 2026-05-26 | remove candidate | `app/Http/Controllers/Api/HubController.php`, `app/Http/Controllers/Api/HubTokenController.php`, `app/Http/Controllers/Api/GeodataController.php`, `app/Http/Middleware/AuthenticateHubToken.php` | No active web/API routes mount these controllers or middleware. The runtime route list has no Hubs/Geodata endpoints. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `resources/views/swagger-hubs.blade.php` and `public/openapi/hubs.yaml` | No active route exposes this view. YAML documents `PBB - HQ API`, not Hotline. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `resources/js/geodata-map-runtime.js` | Not included in `vite.config.js`, not imported by active JS entries, and references `/geodata/ph.json`, which is not present in active routes/files. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `resources/css/app.css` | Not listed in `vite.config.js`; no Blade view includes it. Contains old `.hubs-*` and `.geodata-*` styles. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `public/icons/caller-pwa-192.png`, `public/icons/caller-pwa-512.png` | No manifest, Blade, service-worker static path, or tests reference these files. Current manifest uses `/favicon-*.png`. | Removed after approval. |
| removed 2026-05-26 | remove candidate | `.env.example` entry `HOTLINE_CALLER_SESSION_LIFETIME=43200` | Docs say the legacy fallback was removed; `config/session.php` uses `HOTLINE_CITIZEN_SESSION_LIFETIME` / `HOTLINE_CRITICAL_SESSION_LIFETIME`, not the caller env var. | Removed after approval. |

## Needs Decision

| Cleanup Status | Review Status | Artifact(s) | Evidence | Decision Needed |
| --- | --- | --- | --- | --- |
| pending decision | needs decision | `app/Console/Commands/CheckHubHeartbeats.php`, `app/Services/HubHeartbeatChecker.php`, `app/Models/Hub.php`, `HubToken.php`, `HubUplink.php`, `HubHeartbeatCheck.php` | `app:check-hub-heartbeats` is still registered and scheduled every minute in `routes/console.php`; README also lists it. However, this repo has no migrations creating `hubs`, `hub_tokens`, `hub_uplinks`, or `hub_heartbeat_checks`. | Decide whether Hotline still owns hub heartbeat polling. If no, remove command/schedule/models/docs. If yes, add/restore migrations and route/docs ownership. |
| pending decision | needs decision | `app/Models/GeoRegion.php`, `GeoProvince.php`, `GeoCity.php`, `GeoBarangay.php` | Controllers reference these models, but active routes do not mount Geodata endpoints and no migrations create `geo_regions`, `geo_provinces`, `geo_cities`, or `geo_barangays`. | Decide whether Hotline should retain local geodata admin. Current MapServer/Kit direction suggests this likely belongs outside Hotline. |

## Keep For Now

| Cleanup Status | Review Status | Artifact(s) | Evidence | Reason |
| --- | --- | --- | --- | --- |
| retained | keep for now | `public/citizen-sw.js` cleanup for `caller-pwa-*` caches | `docs/citizen-protocol-decommission-inventory.md` says to keep this cache cleanup until at least one additional production release after caller assets are removed. | This is an intentional compatibility guard, not an orphan. |
| retained | keep for now | `App\Models\User` and `App\Models\Setting` alias models | Many tests/controllers still import `App\Models\User` and `App\Models\Setting`; they alias the domain models. | Active compatibility layer. Do not remove without broad import migration. |
| retained | keep for now | historical migration files with `caller_*` columns/tables | The durable storage migration docs explicitly say not to rewrite old migrations unless the baseline is squashed. | Keep migration history intact. |

## Active Legacy Naming Debt

These are not orphaned; they are active code paths that still use `caller` naming internally while exposing citizen-canonical behavior.

Examples:
- `resources/js/surfaces/surfaceShared.js` has active runtime keys like `callerPendingState`, `callerRealtimeStream`, and `callerPrimerReport`.
- `resources/js/surfaces/operatorSurface.js` still uses helper functions and aliases such as `workbenchCallerName`, `caller_location`, and `caller_id` for compatibility with active Realtime and workbench payloads.
- tests intentionally assert legacy caller routes/assets are removed while some internal aliases remain.

Recommendation:
- do not remove these as orphan cleanup
- handle as a separate citizen-canonical naming refactor with focused JS/API contract tests

## Suggested Cleanup Order After Approval

1. Remove standalone inactive generic API controllers.
2. Remove inactive Hubs/Geodata HTTP docs/views/assets if Hub ownership is confirmed out of scope.
3. Resolve the scheduled `app:check-hub-heartbeats` decision before deleting Hub models.
4. Remove unused legacy PWA icon files and `.env.example` caller lifetime entry.
5. Run route list, PHP tests, JS contract tests, and Vite build.

Recommended verification after each approved cleanup batch:

```powershell
& "C:\wamp64\bin\php\php8.2.29\php.exe" artisan route:list
& "C:\wamp64\bin\php\php8.2.29\php.exe" artisan test
npm run build
node tests\js\citizenSurfaceContracts.test.mjs
node tests\js\citizenRealtimeEvents.test.mjs
node tests\js\operatorChatContracts.test.mjs
```
