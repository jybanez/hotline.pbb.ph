<?php

namespace App\Domain\Shared\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';
    case Pending = 'pending';
}
