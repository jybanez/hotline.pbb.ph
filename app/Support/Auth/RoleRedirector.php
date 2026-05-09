<?php

namespace App\Support\Auth;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Users\Models\User;

class RoleRedirector
{
    public function homePathFor(?User $user): string
    {
        if ($user?->role?->isCitizen()) {
            return '/citizen';
        }

        return match ($user?->role) {
            UserRole::Operator => '/operator',
            UserRole::Admin => '/admin',
            UserRole::Command => '/command',
            default => '/',
        };
    }
}
