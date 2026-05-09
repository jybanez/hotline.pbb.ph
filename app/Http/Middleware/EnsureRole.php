<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Enums\UserRole;
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

        if (! $this->roleIsAllowed($user->role, $roles)) {
            return redirect()->route('unauthorized');
        }

        return $next($request);
    }

    /**
     * @param array<int, string> $roles
     */
    private function roleIsAllowed(UserRole $role, array $roles): bool
    {
        if (in_array($role->value, $roles, true)) {
            return true;
        }

        if ($role->isCitizen() && array_intersect($roles, UserRole::citizenValues()) !== []) {
            return true;
        }

        return false;
    }
}
