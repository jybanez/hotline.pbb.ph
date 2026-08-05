<?php

namespace App\Support\Database;

class DestructiveDatabaseCommandGuard
{
    /**
     * @var array<int, string>
     */
    private const BLOCKED_COMMANDS = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'schema:load',
    ];

    public function isBlockedCommand(?string $command): bool
    {
        return in_array((string) $command, self::BLOCKED_COMMANDS, true);
    }

    public function isExplicitlyAllowed(?string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }

    public function isDisposableDatabase(?string $database): bool
    {
        $name = strtolower(trim((string) $database));

        if ($name === '' || $name === ':memory:') {
            return true;
        }

        return str_ends_with($name, '_test')
            || str_contains($name, '_test_')
            || str_contains($name, '_smoke')
            || str_contains($name, '_rehearsal')
            || str_contains($name, '_testing');
    }
}
