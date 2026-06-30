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

## Handle Callback

```php
$identity = $account->handleCallback($_GET);
$_SESSION['pbb_user'] = $identity->toArray();
```

Apps should provision or update their own local user by `pbb_user_id`. App-local roles, permissions, and domain records remain owned by the consuming app.

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
- Use exact callback URLs registered in the Account trusted-client admin surface.
- Treat Account sessions and app-local sessions as separate sessions.
