# PBB Account SDK for Plain PHP

Framework-neutral SDK for integrating local PBB apps with the local PBB Account Service.

## Install

Composer:

```bash
composer require pbb/account-sdk
```

Drop-in:

```php
require_once __DIR__.'/sdk/php/pbb_account_sdk.php';
```

## Redirect to Account

```php
use Pbb\AccountSdk\AccountClient;
use Pbb\AccountSdk\AccountConfig;

$account = new AccountClient(new AccountConfig([
    'base_url' => 'https://account.pbb.ph',
    'client_id' => 'pbb-chat',
    'client_secret' => getenv('PBB_ACCOUNT_CLIENT_SECRET'),
    'redirect_uri' => 'https://chat.pbb.ph/auth/account/callback',
]));

header('Location: '.$account->authorizationUrl());
exit;
```

`authorizationUrl()` stores both OAuth `state` and a 64-character `nonce` in the configured state store. Account binds the nonce to the one-time authorization code and echoes it during token exchange.

## Handle Callback

```php
$identity = $account->handleCallback($_GET);
$_SESSION['pbb_user'] = $identity->toArray();
```

`handleCallback()` validates `state`, sends the stored `nonce` to `/oauth/token`, and rejects the callback if Account does not echo the same nonce. Older callbacks that were started without an SDK-stored nonce remain supported for compatibility.

Apps should provision or update their own local user by `pbb_user_id`. App-local roles, permissions, and domain records remain owned by the consuming app.

If the app also needs `account_session_id` for the Account JS session-sync SDK, use `handleCallbackToken()`:

```php
$token = $account->handleCallbackToken($_GET);
$_SESSION['pbb_user'] = $token->identity->toArray();
$_SESSION['account_session_id'] = $token->accountSessionId;
```

## Resolve Account Identity For App User Lists

Use this from the app backend when rendering app-local user lists:

```php
$identities = $account->resolveIdentities([
    '01KW...',
    '01KX...',
]);

$identity = $identities['01KW...']; // AccountIdentity|null
```

The app should query its own users, roles, and domain state locally, then enrich display fields from Account by `pbb_user_id`.

## Update Account-Owned Identity

If an app has its own profile form for shared identity fields, send those fields back to Account:

```php
$identity = $account->updateIdentity($pbbUserId, [
    'name' => 'Updated Name',
    'email' => 'updated@pbb.local',
    'mobile' => '09170000000',
    'account_session_id' => $_SESSION['account_session_id'] ?? null,
]);
```

Supported fields are `name`, `email`, `mobile`, and optional `account_session_id`. Avatar URLs remain Account-generated from Account uploads.

## Readiness

```php
if (! $account->isReady()) {
    http_response_code(503);
}
```

## Runnable Demo

The SDK includes a plain PHP demo app in `sdk/php/demo`.

1. In Account admin, create a trusted client such as `pbb-sdk-demo`.
2. Add this redirect URI:

```text
http://127.0.0.1:8091/callback.php
```

3. Copy the demo config:

```powershell
Copy-Item sdk\php\demo\config.local.example.php sdk\php\demo\config.local.php
```

4. Paste the generated client secret into `sdk/php/demo/config.local.php`.
5. Start the demo:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe -S 127.0.0.1:8091 -t sdk\php\demo
```

6. Open:

```text
http://127.0.0.1:8091
```

## Security Notes

- Keep `client_secret` on the server.
- Do not exchange authorization codes from browser JavaScript.
- Do not call identity resolve/update helpers from browser JavaScript; they use trusted-client credentials.
- Use exact callback URLs registered in the Account trusted-client admin surface.
- Use the SDK-managed `state` and `nonce` flow for new integrations.
- Treat Account sessions and app-local sessions as separate sessions.
