<?php

namespace App\Http\Middleware;

use App\Support\Auth\RoleRedirector;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function __construct(
        private readonly RoleRedirector $roleRedirector,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('public.home');
        }

        if (! in_array($user->role->value, $roles, true)) {
            return redirect()->route('unauthorized');
        }

        return $next($request);
    }
}
