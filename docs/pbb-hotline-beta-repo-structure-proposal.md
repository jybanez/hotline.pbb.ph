# PBB Hotline Beta Repo Structure Proposal

Date: 2026-04-04

Status: Draft recommended Laravel repo shape for Phase 1 kickoff

References:
- [PBB Hotline Beta Proposal](./pbb-hotline-beta-proposal.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Laravel Application Baseline](C:/wamp64/www/pbb/hub.ph/docs/pbb-laravel-application-baseline.md)

Purpose:
- give the Beta team a concrete repo shape before feature coding starts
- keep Laravel standard structure intact
- separate role surfaces and domain areas without turning the app into a package-heavy monolith

Kickoff assumptions:
- target project location: `c:/wamp64/www/pbb/hotline`
- frontend should adopt the dark theme of the PBB library from the start

## 1. Design Rules

- keep normal Laravel top-level folders
- keep business domains in `app/Domain/*`
- keep HTTP delivery in `app/Http/*`
- keep shared browser/bootstrap/session code in `app/Support/*`
- keep separate frontend entrypoints per surface
- avoid Alpha's single-bundle approach
- use Helper as the UI contract layer, not ad hoc Blade widgets

## 2. Recommended Top-Level Shape

Recommended repo shape:

```text
app/
  Domain/
  Http/
  Policies/
  Providers/
  Realtime/
  Support/
bootstrap/
config/
database/
  factories/
  migrations/
  seeders/
docs/
public/
resources/
  css/
  images/
  js/
  views/
routes/
storage/
tests/
```

## 3. Recommended `app/` Structure

### `app/Domain`

Suggested domain folders:

```text
app/Domain/
  Admin/
  AlertLevels/
  Calls/
  Incidents/
  Media/
  Messages/
  Settings/
  Teams/
  Users/
```

Recommended contents by domain:

- `Models/`
- `Data/` or `DTOs/`
- `Enums/` or `Constants/`
- `Actions/`
- `Services/`
- `Queries/`
- `Policies/` only when domain-specific policy classes are clearer than global `app/Policies`

### `app/Http`

Suggested HTTP structure:

```text
app/Http/
  Controllers/
    Api/
      Admin/
      Caller/
      Operator/
      Public/
      Realtime/
      Session/
    Web/
  Middleware/
  Requests/
    Admin/
    Caller/
    Operator/
    Session/
  Resources/
```

Recommended rule:
- controllers stay thin
- request validation lives in Form Requests
- API Resources shape the public contract where useful
- business workflow logic stays in domain actions/services

### `app/Support`

Suggested shared support folders:

```text
app/Support/
  Auth/
  Bootstrap/
  DevicePrimer/
  Frontend/
  Sessions/
  Settings/
```

Use this layer for:
- surface bootstrap payload assembly
- role-based redirect decisions
- re-auth/session-expiry handling
- operator workbench overlay-state restore
- activity-aware session keepalive orchestration
- frontend asset/page identity helpers
- shared settings resolution

### `app/Realtime`

Suggested Realtime-focused structure:

```text
app/Realtime/
  Admissions/
  Broadcasts/
  Channels/
  Presence/
  Signaling/
```

Use this layer for:
- caller/operator admission payload assembly
- room naming helpers
- broadcast event classes
- call-signaling orchestration that is transport-facing rather than pure domain state

## 4. Recommended `routes/` Structure

Keep Laravel's standard route files, but split the actual declarations into smaller includes.

Suggested structure:

```text
routes/
  api.php
  web.php
  channels.php
  console.php
  auth.php
  api/
    admin.php
    caller.php
    operator.php
    public.php
    realtime.php
    session.php
  web/
    admin.php
    caller.php
    operator.php
    public.php
```

Recommended route ownership:

- `routes/web/public.php`
  - `/`
- `routes/web/caller.php`
  - `/caller`
- `routes/web/operator.php`
  - `/operator`
- `routes/web/admin.php`
  - `/admin`
- `/command` should exist only when Phase 2 starts

- `routes/api/public.php`
  - bootstrap
  - public alert level
- `routes/api/session.php`
  - login
  - logout
  - reauth
  - current account
- `routes/api/caller.php`
  - caller home
  - new call attempt
  - reconnect
  - caller incidents
- `routes/api/operator.php`
  - dashboard
  - workbench
  - status changes
  - transfers
  - assignments
- `routes/api/admin.php`
  - admin summary
  - grids
  - settings
- `routes/api/realtime.php`
  - caller/operator admission endpoints

## 5. Recommended Frontend Structure

### `resources/js`

Use surface entrypoints and shared feature folders.

Suggested structure:

```text
resources/js/
  entries/
    public.js
    caller.js
    operator.js
    admin.js
  bootstrap/
    app-bootstrap.js
    session-handling.js
    role-redirects.js
  components/
  features/
    admin/
    caller/
    operator/
    public/
    shared/
  helpers/
    navbar.js
    user-menu.js
    device-primer.js
  services/
    api-client.js
    realtime-client.js
    settings-stream.js
```

Recommended rule:
- surface entrypoints boot pages
- `features/shared` holds genuinely shared code only
- operator-specific Realtime/workbench logic must stay out of caller/admin bundles

### `resources/css`

Suggested structure:

```text
resources/css/
  shared.css
  public.css
  caller.css
  operator.css
  admin.css
```

Recommended rule:
- one shared base stylesheet
- one page/surface stylesheet per major surface
- avoid one global all-role stylesheet

### `resources/views`

Keep Blade minimal and shell-oriented.

Suggested structure:

```text
resources/views/
  layouts/
    guest.blade.php
    app.blade.php
  shells/
    public.blade.php
    caller.blade.php
    operator.blade.php
    admin.blade.php
  pages/
    public/home.blade.php
    caller/index.blade.php
    operator/index.blade.php
    admin/index.blade.php
    unauthorized.blade.php
```

Recommended rule:
- Blade renders shell containers and asset entrypoints
- operational cards, tables, lists, and workbench content render client-side after bootstrap
- overlay-heavy UI such as the operator workbench should use explicit client restore state so refresh and re-auth can restore it without requiring URL mutation

## 6. Recommended Vite Entry Strategy

Recommended Vite inputs:

- `resources/css/shared.css`
- `resources/css/public.css`
- `resources/css/caller.css`
- `resources/css/operator.css`
- `resources/css/admin.css`
- `resources/js/entries/public.js`
- `resources/js/entries/caller.js`
- `resources/js/entries/operator.js`
- `resources/js/entries/admin.js`

Recommended outcome:
- separate bundles per surface
- shared vendor chunks generated by Vite where useful
- no forced single entrypoint for every role

## 7. Recommended Test Structure

Suggested structure:

```text
tests/
  Feature/
    Admin/
    Caller/
    Operator/
    Public/
    Session/
  Unit/
    Calls/
    Incidents/
    Messages/
    Settings/
    Teams/
```

Priority Phase 1 tests:
- login / logout / re-auth
- wrong-role unauthorized behavior
- new call attempt creation rules
- reconnect blocking rules
- incident resolve guard requiring completed/cancelled assignments
- transfer accept/reject rules
- admin blocked-delete behavior

## 8. Recommended Immediate Kickoff Work

The Beta team can scaffold this repo in the following order:

1. add surface shells and Vite entrypoints
2. split route files into public/caller/operator/admin/session/realtime areas
3. create bootstrap/account/session support layer
4. add domain folders and initial models
5. add Realtime admission endpoints and room helper layer
6. start Phase 1 migrations from the migration plan

## 9. Bottom Line

The recommended Beta repo should stay recognizably Laravel, but be organized around:
- role surfaces
- domain actions/services
- thin HTTP controllers
- separate frontend bundles
- explicit Realtime support

That is enough structure for Phase 1 without overengineering the project.
