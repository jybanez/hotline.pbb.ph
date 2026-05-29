# PBB Hotline Installer Contract

This document describes the first Kit Setup-facing installer contract for PBB Hotline.

Hotline release bundles are ready-to-run. They must include `vendor/`, `public/build/`, a lean production Helper runtime at `public/vendor/helpers.pbb.ph`, the vendored Realtime backend SDK under `app/Support/Realtime/Sdk`, the vendored Realtime browser SDK under `resources/js/vendor/pbb-realtime-sdk`, and the app-owned `ffmpeg` binary under `bin/ffmpeg/`. Target installs should not run Composer or Vite builds and should not depend on third-party application paths for FFmpeg.

## Entrypoints

- `release.json`: release metadata and Kit Setup-facing installer/tool declarations.
- `installer/install-run.php`: unattended installer runner. `preflight` mode performs host/config validation and repairs app-owned writable runtime folders before checking permissions. `fresh` mode implements first install. `upgrade` and `repair` preserve `.env` and runtime data, run bounded Laravel maintenance actions, regenerate caches, emit reports, and leave service restart orchestration to Kit.
- `installer/status.php`: install status command. The current first slice reports whether installer artifacts exist.
- `installer/schema/install.schema.json`: unattended config schema.
- `installer/docs/hotline-install.sample.json`: sample config.
- `tools/populate-initial-data.php`: Data Prep Prepare Data tool for Hotline reference data. It supports dry runs, packaged fallback source data, idempotent source-backed writes, and machine-readable reports.
- `tools/data-prep/verify.php`: Data Prep Verify tool for the required Hotline reference-data tables.

`tools/prepare-helper-runtime.php` is a repo/CI build-time helper only. It prepares the Helper runtime before packaging and should not be included in installable bundles.

## Preflight Checks

`installer/install-run.php --mode preflight` repairs and checks:

- PHP version is at least 8.2.
- required PHP extensions are loaded.
- ready-to-run bundle files exist: `vendor/autoload.php`, `public/build/manifest.json`, Helper UI bundle, vendored Realtime SDK, and bundled `bin/ffmpeg/ffmpeg(.exe)`.
- `storage/`, `storage/app`, `storage/framework/cache`, `storage/framework/cache/data`, `storage/framework/sessions`, `storage/framework/views`, `storage/logs`, and `bootstrap/cache` exist and are writable. Missing writable directories are created by the app installer from the deployed app root before writability is checked.
- `app.install_path` must match the extracted app root selected by Kit, `app.public_path` must resolve to `public/` under that root, and app-owned runtime/cache/generated paths such as `hotline.playwright_browsers_path` must remain under `app.install_path` unless Kit explicitly provides a named external path contract.
- config shape includes required app, database, admin, and Hotline keys when `--config` is provided.
- MySQL connection succeeds when database config is provided.
- bundled app-owned `bin/ffmpeg/ffmpeg(.exe)` paths are preferred; configured external `ffmpeg` paths are fallback only. `ffprobe` is optional and external-only because current Hotline runtime media assembly does not call it.
- Realtime HTTPS publish CA bundle is available through `hotline.realtime_ca_bundle` or PHP `curl.cainfo` / `openssl.cafile`.
- SITREP Node binary is available when configured.
- required secrets are present and not placeholder values.
- first admin password is non-placeholder and satisfies Hotline's installer strength policy.
- app, Realtime, Relay, and MapServer URLs are valid.

Running without `--config` is allowed for bundle/host validation and returns warnings for skipped config-dependent checks.

## Fresh Mode Slice

`installer/install-run.php --mode fresh --config <file>` currently handles the first runnable install slice:

- runs full preflight first
- requires a config file
- refuses to overwrite an existing `.env` unless `options.overwrite_env` is `true`
- backs up the existing `.env` before rewrite when overwrite is allowed
- writes a generated `.env` from installer config
- writes `VIEW_COMPILED_PATH` to the absolute in-root `storage/framework/views` path before Laravel config/view cache commands run
- writes session cookie domain from `app.session_domain`, but normalizes accidental local-only values such as `localhost` to the `app.app_url` host when installing for a non-local host
- generates a fresh Laravel `APP_KEY`
- prepares `storage/app/installer` and `storage/app/installer/services`
- applies `database/schema/hotline-schema-mysql.sql` for fresh installs when `options.database_setup=baseline_schema` is set, with `options.use_baseline_schema=true` retained as a backward-compatible fallback
- reports `database_setup.strategy`, `database_setup.baseline_schema`, `database_setup.baseline_schema_used`, `database_setup.migration_rows`, and `database_setup.upgrade_strategy` in fresh install output and manifests
- reserves `php artisan migrate --force` as the fallback path when baseline schema use is explicitly disabled or the artifact is absent
- skips `SettingsSeeder` for fresh baseline-schema installs because production initial settings are already in the baseline schema; if baseline schema is disabled and `database/seeders/SettingsSeeder.php` is packaged, runs `php artisan db:seed --class=SettingsSeeder --force` when `options.seed_settings` is enabled
- applies Realtime, Relay, and MapServer runtime settings
- writes `HOTLINE_FFMPEG_BINARY` to the resolved app-owned bundled binary under `bin/ffmpeg` when it exists, even if Kit supplied an external FFmpeg path. External paths remain fallback only. `HOTLINE_FFPROBE_BINARY` is written only when an external/configured or PATH-resolvable `ffprobe` exists.
- writes `HOTLINE_REALTIME_CA_BUNDLE` to `hotline.realtime_ca_bundle` when provided, otherwise to the PHP runtime CA bundle detected from `curl.cainfo` or `openssl.cafile`.
- creates the first admin account when missing, using the Kit Setup first-admin contract, when `options.create_admin` is enabled
- creates `public/storage` with `php artisan storage:link --force`
- caches config, routes, and views when `options.cache_config` is enabled
- generates queue worker and scheduler service artifacts under `storage/app/installer/services`
- runs post-install health checks when `options.validate_after_install` is enabled
- writes `storage/app/installer/install-manifest.json` after the install commands pass
- writes `storage/app/installer/install-report.json` for status/discovery, even when `--report` also writes a copy elsewhere
- reports filesystem boundary details in the install manifest: app root, app-owned paths created or relied on, and external binary paths relied on without creating them
- returns planned actions and a manifest preview when `--dry-run` is used

Direct service registration is intentionally not performed by default. The installer writes service artifacts for Kit Setup or an operator to register. If config requests direct registration without `--no-service-register`, the current slice fails with a structured message instead of pretending registration happened.

## Upgrade And Repair Modes

`installer/install-run.php --mode upgrade --config <file>` and `--mode repair --config <file>` are intended for already installed app roots after Kit has deployed or restored app files.

Both modes:

- run full preflight first and require a config file
- require an existing `.env`
- preserve the existing `.env`; they do not rewrite, delete, or back it up
- preserve `storage/` runtime data and recreate only missing app-owned writable directories
- run `php artisan migrate --force` when `options.run_migrations` is enabled
- re-apply runtime settings through `installer/bootstrap-runtime.php --skip-admin`; existing admin accounts and passwords are not created or reset during normal upgrade/repair
- skip `admin.password` and `admin_password_strength` preflight checks during normal upgrade/repair because no admin password write is planned
- run `php artisan storage:link --force`
- regenerate config, route, and view caches when `options.cache_config` is enabled
- regenerate queue/scheduler service artifact files under `storage/app/installer/services`
- run the same post-install health checks when `options.validate_after_install` is enabled
- write `storage/app/installer/install-manifest.json` and `storage/app/installer/install-report.json`

Rollback support is file-level: Kit can restore the previous app files while Hotline preserves existing `.env`, `storage/`, and database state. Hotline's runner does not attempt database rollback.

If an operator intentionally needs an admin password repair during maintenance, Kit must request it explicitly with `options.maintenance_admin_bootstrap=true` or `admin.overwrite_existing=true`; only then does Hotline require and validate `admin.password`.

## First Admin Contract

Hotline follows Kit Setup's shared Laravel first-admin contract:

- default name: `PBB Administrator`
- default email: `admin@pbb.local`
- default strategy: `create_if_missing`
- default `overwrite_existing`: `false`
- default `must_change_password`: `false`
- password must be non-placeholder, at least 12 characters, and include uppercase, lowercase, and numeric characters
- existing admin passwords are not reset unless `admin.overwrite_existing` is explicitly `true`
- raw passwords are not printed in installer reports, manifests, logs, or status output

Post-install health checks cover:

- storage writeability
- `php artisan about --only=environment`
- `php artisan queue:failed`
- `php artisan schedule:list`
- bundled app-owned `ffmpeg`, plus optional external `ffprobe` if configured or available on PATH
- `APP_URL/up`
- `APP_URL/api/bootstrap?surface=public`

## Core Install Scope

The core installer should only make Hotline runnable:

- write `.env`
- generate `APP_KEY`
- validate bundled `vendor/`, `public/build`, Helper UI assets, and Realtime SDK
- run migrations
- seed settings only when the baseline schema is not used and a production settings seeder is packaged
- create the first admin account
- apply Realtime, Relay, MapServer, media, and session settings
- create storage links and writable directories
- generate queue worker and scheduler service artifacts
- run health checks
- write install manifest and report JSON

For Windows installs, Kit should pass `hotline.realtime_ca_bundle` when it owns the CA bundle location. If omitted, Hotline's installer uses the PHP runtime CA bundle detected during install. Hotline never disables TLS verification for Realtime publish calls.

## Helper Runtime Packaging

Hotline keeps the full `public/vendor/helpers.pbb.ph` checkout available for local development, but release packaging should not include the full Helper repository. The production browser path loads `js/ui/ui.loader.js` and then prefers the rebuilt `dist/helpers.ui.bundle.min.js` / `dist/helpers.ui.bundle.min.css` bundle for `ui.*` and `incident.*` components.

Before assembling a release package, run:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe tools\prepare-helper-runtime.php --clean
```

Package `storage/app/installer/helper-runtime/helpers.pbb.ph` as `public/vendor/helpers.pbb.ph` in the release artifact. This prepared runtime includes:

- `js/ui/ui.loader.js`
- `dist/helpers.ui.bundle.min.js`
- `dist/helpers.ui.bundle.min.css`
- `package.json` when present
- `boot.*.json` metadata when present

Exclude Helper `.git`, `node_modules`, demos, docs, samples, scripts, tests, temporary files, and source-only assets from release packages.

Also exclude `tools/prepare-helper-runtime.php` itself from distributable bundles. It belongs in the project repo or CI build environment, not on deployed nodes. Distributables should ship the prepared Helper runtime and the Kit-facing installer contract, not source-only packaging helpers.

## Distributable Exclusions

Release packaging must not copy the Hotline working tree wholesale. The installable bundle should exclude:

- local environment and secrets: `.env`, `*.key`, `*.pem`, `*.crt`, and certificate/key backups
- repository/editor metadata: `.git`, `.gitignore`, `.gitattributes`, `.gitmodules`, `.editorconfig`, `.vscode`, `.codex`
- temporary/generated work folders: `.scaffold_tmp`, `.tmp*`, `.codex_tmp_*`, `outputs`, `tmp`, `storage`
- test/build inputs: `tests`, `node_modules`, `phpunit.xml`, `.phpunit.result.cache`, `package.json`, `package-lock.json`, `vite.config.js`, `composer.lock`
- source-only docs and packaging helpers: top-level `docs` and `tools/prepare-helper-runtime.php`
- demo/test database mutators: `app/Console/Commands/ClearTestIncidents.php`, `app/Console/Commands/SeedSampleSitrepIncidents.php`, and `database/seeders/DevUsersSeeder.php`

Keep `composer.json` because Laravel resolves the application namespace from its PSR-4 map at runtime. Keep `tools/populate-initial-data.php`, `tools/data-prep/apply-settings.php`, `tools/data-prep/verify.php`, and `resources/data/hotline/reference-data.json` because Kit Data Prep uses them. Keep `.env.example` because the installer preflight validates its presence and it contains no deployment secrets.

## Data Prep Scope

Operational/reference data belongs in Data Prep tools, not hidden inside fresh install. Core Setup install should stop after install verification and smoke checks; Data Prep is a separate operator workflow.

Hotline declares `release.json.data_prep.version=1`:

- `prepare_data=true`: mapped to `tools/populate-initial-data.php`
- `apply_settings=true`: mapped to `tools/data-prep/apply-settings.php`
- `verify=true`: mapped to `tools/data-prep/verify.php`

The Prepare Data tool supports `--config`, `--report`, `--dry-run`, and `--mode initial|repair|refresh|demo`. It reads full installer config under `hotline.populate`, a root `populate` object, or a direct population config. When `hotline.populate.enabled=true` and no usable managed source exists, it defaults to `resources/data/hotline/reference-data.json`.

Supported initial Data Prep groups are:

- `incident_categories`
- `incident_types`
- `incident_type_fields`
- `resource_type_categories`
- `resource_types`
- `incident_type_default_resources`
- `team_categories`
- `teams`
- `team_resource_inventories`

Current packaged reference data plans/imports 215 records across those groups. Writes are idempotent by stable names; `overwrite_existing=true` updates matching reference rows, while `false` leaves existing rows untouched and creates only missing rows.

The Apply Settings tool writes Realtime runtime settings into Hotline's `settings` table. The stable Realtime values are `clt_PBB_HOTLINE`, `prj_HOTLINE_SERVER`, `prj_HOTLINE_CITIZEN`, `prj_HOTLINE_OPERATOR`, and `prj_HOTLINE_COMMAND`. Media ingest intentionally uses `prj_HOTLINE_OPERATOR`; no `prj_HOTLINE_MEDIA` project scope is required.

Operators, users, dispatch defaults, fake calls, fake incidents, fake media, and demo SITREPs are outside the initial Hotline Data Prep scope. Stale `operators` and `dispatch_defaults` source keys are ignored with report warnings.

The Verify tool writes a secret-safe report and confirms the required reference tables meet the packaged minimum counts. When Apply Settings config is present, it also verifies the expected Realtime runtime setting values.

## Bundle Handoff

Operator-facing bundles must use the canonical package name `pbb-hotline-5.6.1.zip`, matching `release.json` app code and version. Do not hand Kit Setup suffixed diagnostic bundles such as `-baseline`, `-fixed`, `-data-prep`, `-hotfix`, or branch-name variants. Any Data Prep metadata, source, tool, report, checksum, installer, or media-binary ownership change must be rebuilt into the canonical bundle and then embedded into a rebuilt Kit Setup installer before operator testing.
