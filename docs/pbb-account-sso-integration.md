# PBB Account SSO Integration

Hotline uses PBB Account for the primary public/citizen login path when Account SSO is enabled. PBB Account owns identity, credentials, account status, and browser SSO. Hotline still owns its local web session, roles, permissions, citizen state, incidents, and app authorization.

## Trusted Client

Configure the trusted client in PBB Account:

```text
client_id: pbb-hotline
redirect_uri: https://hotline.pbb.ph/auth/account/callback
post_logout_redirect_uri: https://hotline.pbb.ph
```

## Hotline Environment

```env
PBB_ACCOUNT_SSO_ENABLED=true
PBB_ACCOUNT_BASE_URL=https://account.pbb.ph
PBB_ACCOUNT_CLIENT_ID=pbb-hotline
PBB_ACCOUNT_CLIENT_SECRET=pbb-hotline-dev-secret
PBB_ACCOUNT_REDIRECT_URI=https://hotline.pbb.ph/auth/account/callback
PBB_ACCOUNT_POST_LOGOUT_REDIRECT_URI=https://hotline.pbb.ph
PBB_ACCOUNT_SCOPES="openid profile"
PBB_ACCOUNT_TIMEOUT_SECONDS=10
PBB_ACCOUNT_CA_BUNDLE=
```

Rotate `PBB_ACCOUNT_CLIENT_SECRET` outside development.

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

Configuration:

```env
PBB_ACCOUNT_ADMIN_API_ENABLED=false
PBB_ACCOUNT_ADMIN_API_TOKEN=
PBB_ACCOUNT_ADMIN_API_CLIENT=pbb-account
```

Fresh installs can enable the service API through the installer config:

```json
{
  "hotline": {
    "pbb_account_admin_api_enabled": true,
    "pbb_account_admin_api_token": "replace-with-kit-generated-service-token"
  }
}
```

`PBB_ACCOUNT_ADMIN_API_TOKEN` must be a dedicated Account app-admin service token. Do not reuse `PBB_ACCOUNT_CLIENT_SECRET`.

Endpoints:

- `GET /api/account-admin/meta`
- `GET /api/account-admin/users/{pbb_user_id}`
- `PUT /api/account-admin/users/{pbb_user_id}`
- `PATCH /api/account-admin/users/{pbb_user_id}/role`
- `PATCH /api/account-admin/users/{pbb_user_id}/status`

Hotline publishes its own role vocabulary: `admin`, `command`, `operator`, `citizen`.

Hotline publishes its own status vocabulary from the local user status enum: `active`, `suspended`, `disabled`, `pending`.

`PUT /users/{pbb_user_id}` is idempotent. It creates a local user when no link exists, links an existing unlinked user by email when safe, and updates only safe identity fields when the link already exists. Role and status remain Hotline-owned; Account requests changes through the dedicated role/status patch endpoints.
