<?php

namespace App\Domain\Shared\Enums;

enum UserRole: string
{
    case Caller = 'caller';
    case Operator = 'operator';
    case Command = 'command';
    case Admin = 'admin';
}
