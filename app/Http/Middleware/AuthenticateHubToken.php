<?php

namespace App\Http\Middleware;

use App\Models\HubToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHubToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->bearerToken();

        if (! $header) {
            return $this->error('Hub token required.', 401);
        }

        $token = HubToken::query()
            ->with('hub')
            ->where('token_hash', hash('sha256', $header))
            ->first();

        if (! $token || ! $token->hub) {
            return $this->error('Invalid hub token.', 401);
        }

        if ($token->revoked_at || $token->hub->status !== 'active') {
            return $this->error('Hub token is inactive.', 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('hubToken', $token);
        $request->attributes->set('authenticatedHub', $token->hub);

        return $next($request);
    }

    private function error(string $message, int $status): Response
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'meta' => null,
            'error' => $message,
        ], $status);
    }
}
