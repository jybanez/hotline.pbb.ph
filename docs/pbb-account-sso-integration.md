# PBB Account SSO Integration

Hotline uses PBB Account for the primary public/citizen login path when Account SSO is enabled. PBB Account owns identity, credentials, account status, and browser SSO. Hotline still owns its local web session, roles, permissions, citizen state, incidents, and app authorization.

## Trusted Client

Configure the trusted client in PBB Account:

```text
client_id: pbb-hotline
redirect_uri: https://hotline.pbb.ph/auth/account/callback
post_logout_redirect_uri: https://hotline.pbb.ph
```

## Hotline Runtime Settings

Hotline stores browser Account SSO settings in the local `settings` table so shared WAMP/Apache/PHP environment variables cannot bleed between sibling PBB apps.

Runtime settings:

- `account_sso_enabled`
- `account_sso_base_url`
- `account_sso_client_id`
- `account_sso_client_secret`
- `account_sso_redirect_uri`
- `account_sso_post_logout_redirect_uri`
- `account_sso_scopes`
- `account_sso_ca_bundle`

The legacy `PBB_ACCOUNT_*` environment values remain compatibility fallbacks for source/dev work only. Fresh installs should write Account SSO values through installer/bootstrap into the app-local settings table so the shared WAMP/Apache/PHP runtime cannot bleed Account values between sibling PBB apps.

PBB Account and Kit own the trusted-client profile: Account URL, OAuth client identifier, redirect URI, post-logout redirect URI, approved scopes, and CA bundle provisioning. Hotline stores the local DB-backed values it needs to initiate and complete SSO, preferably provisioned by Kit/bootstrap. These trusted-client details are hidden from the Admin Runtime Settings modal so day-to-day administrators do not edit Account-owned registration values.

The Admin Runtime Settings modal may show `account_sso_client_secret` through the normal Helper password field. Admins who keep a trusted copy may use the password reveal control to visually verify the stored OAuth secret without rotating it.

`account_sso_client_secret` is the OAuth client secret used during authorization-code token exchange. It is separate from `account_admin_api_token`, which is the Account-to-Hotline app-admin service token.

Fresh install config:

```json
{
  "hotline": {
    "pbb_account_sso_enabled": true,
    "pbb_account_base_url": "https://account.pbb.ph",
    "pbb_account_client_id": "pbb-hotline",
    "pbb_account_client_secret": "replace-with-account-oauth-client-secret",
    "pbb_account_redirect_uri": "https://hotline.pbb.ph/auth/account/callback",
    "pbb_account_post_logout_redirect_uri": "https://hotline.pbb.ph",
    "pbb_account_scopes": "openid profile"
  }
}
```

## Runtime Flow

1. Public/citizen login redirects the browser to `/auth/account/redirect`.
2. Hotline redirects to PBB Account `/oauth/authorize`.
3. PBB Account redirects back to `/auth/account/callback` with a code.
4. Hotline exchanges the code server-side through the vendored plain PHP Account SDK.
5. Hotline matches or provisions a local citizen user by `pbb_user_id`.
6. Hotline creates a normal local Laravel web session and redirects to `/citizen`.

Hotline does not submit Account credentials to `/api/login`. The local `/api/login` endpoint remains available for existing admin, command, operator, and local fallback citizen accounts.

## Logout

`/auth/logout` clears the Hotline local session first. When Account SSO is enabled it redirects the browser to:

```text
https://account.pbb.ph/oauth/logout?client_id=pbb-hotline&post_logout_redirect_uri=https%3A%2F%2Fhotline.pbb.ph
```

When Account SSO is disabled, `/auth/logout` redirects to `/` after clearing the local Hotline session.

## Account App-Admin API

Hotline exposes a service-only app-admin API for PBB Account under `/api/account-admin`. These endpoints do not use browser sessions and are not a replacement for local Hotline admin authorization.

Required headers:

```http
Authorization: Bearer <PBB_ACCOUNT_ADMIN_API_TOKEN>
X-PBB-Account-Client: pbb-account
Accept: application/json
Content-Type: application/json
```

Runtime settings:

Hotline stores Account app-admin runtime credentials in the local `settings` table:

- `account_admin_api_enabled`
- `account_admin_api_token`
- `account_admin_api_client`

The request-time middleware reads these DB settings only. It does not read `PBB_ACCOUNT_ADMIN_API_*` from `.env`, because shared WAMP/Apache/PHP runtimes can leak generic environment names across sibling PBB apps. Installer/bootstrap tooling may receive Account app-admin values from Kit config, but must write them into the app-local settings table before the API is considered runtime-ready.

The Admin Runtime Settings modal may show `account_admin_api_token` through the normal Helper password field so an administrator can visually verify the stored token against a trusted copy. This token remains separate from the Account OAuth client secret.

Fresh installs can enable the service API through the installer config:

```json
{
  "hotline": {
    "pbb_account_admin_api_enabled": true,
    "pbb_account_admin_api_token": "replace-with-kit-generated-service-token"
  }
}
```

`account_admin_api_token` must be a dedicated Account app-admin service token. Do not reuse `account_sso_client_secret`. Account owns app-admin token issuance and rotation; Hotline owns storing the active DB-backed token used by its service middleware.

Endpoints:

- `GET /api/account-admin/meta`
- `GET /api/account-admin/users/{pbb_user_id}`
- `PUT /api/account-admin/users/{pbb_user_id}`
- `PATCH /api/account-admin/users/{pbb_user_id}/role`
- `PATCH /api/account-admin/users/{pbb_user_id}/status`

Hotline publishes its own role vocabulary: `admin`, `command`, `operator`, `citizen`.

Hotline publishes its own status vocabulary from the local user status enum: `active`, `suspended`, `disabled`, `pending`.

`PUT /users/{pbb_user_id}` is idempotent. It creates a local user when no link exists, links an existing unlinked user by email when safe, and updates only safe identity fields when the link already exists. Role and status remain Hotline-owned; Account requests changes through the dedicated role/status patch endpoints.
