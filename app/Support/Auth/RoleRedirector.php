<?php

namespace App\Support\Auth;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Users\Models\User;

class RoleRedirector
{
    public function homePathFor(?User $user): string
    {
        return match ($user?->role) {
            UserRole::Caller => '/caller',
            UserRole::Operator => '/operator',
            UserRole::Admin => '/admin',
            UserRole::Command => '/command',
            default => '/',
        };
    }
}
