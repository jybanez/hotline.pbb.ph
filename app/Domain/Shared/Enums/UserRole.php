<?php

namespace App\Domain\Shared\Enums;

enum UserRole: string
{
    case Citizen = 'citizen';
    /** @deprecated Use Citizen. Kept temporarily for existing rows and legacy call-surface routes. */
    case Caller = 'caller';
    case Operator = 'operator';
    case Command = 'command';
    case Admin = 'admin';

    public function isCitizen(): bool
    {
        return in_array($this, [self::Citizen, self::Caller], true);
    }

    public static function citizenValues(): array
    {
        return [self::Citizen->value, self::Caller->value];
    }
}
