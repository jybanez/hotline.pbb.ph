<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAccountAdminService
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('account.admin_api_enabled')) {
            return $this->fail('account_admin_disabled', 'Account admin API is disabled.', 503);
        }

        $configuredClient = trim((string) config('account.admin_api_client', 'pbb-account'));
        $providedClient = trim((string) $request->header('X-PBB-Account-Client'));

        if ($configuredClient === '' || $providedClient !== $configuredClient) {
            return $this->fail('invalid_account_client', 'The Account client header is missing or invalid.', 401);
        }

        $configuredToken = trim((string) config('account.admin_api_token'));
        $providedToken = trim((string) $request->bearerToken());

        if ($providedToken === '') {
            $providedToken = trim((string) $request->header('X-PBB-Account-Admin-Token'));
        }

        if ($configuredToken === '' || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            return $this->fail('invalid_app_admin_token', 'The app-admin token is missing or invalid.', 401);
        }

        return $next($request);
    }

    private function fail(string $code, string $message, int $status): Response
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
            ],
        ], $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
