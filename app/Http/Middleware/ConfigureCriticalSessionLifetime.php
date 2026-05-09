<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureCriticalSessionLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isCriticalSessionRequest($request)) {
            $lifetime = max(
                (int) config('session.lifetime', 120),
                (int) config('session.critical_lifetime', 43200),
            );

            config(['session.lifetime' => $lifetime]);
        }

        return $next($request);
    }

    private function isCriticalSessionRequest(Request $request): bool
    {
        if ($request->is(
            'caller',
            'caller/*',
            'citizen',
            'citizen/*',
            'operator',
            'operator/*',
            'command',
            'command/*',
            'api/caller',
            'api/caller/*',
            'api/citizen',
            'api/citizen/*',
            'api/operator',
            'api/operator/*',
            'api/command',
            'api/command/*',
        )) {
            return true;
        }

        if ($request->is('api/bootstrap', 'api/session/ping')) {
            return in_array($request->query('surface'), ['citizen', 'caller', 'operator', 'command'], true);
        }

        return false;
    }
}
