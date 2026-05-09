<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogLegacyCallerRouteUsage
{
    public function handle(Request $request, Closure $next, string $contract = 'caller'): Response
    {
        Log::info('Hotline legacy caller route used.', [
            'contract' => $contract,
            'method' => $request->method(),
            'path' => $request->path(),
            'route_name' => $request->route()?->getName(),
            'user_id' => $request->user()?->getKey(),
            'user_role' => $request->user()?->role?->value,
        ]);

        return $next($request);
    }
}
