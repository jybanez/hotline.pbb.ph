<?php

namespace App\Domain\Shared\Enums;

enum OperatorRuntimeState: string
{
    case Offline = 'offline';
    case Available = 'available';
    case Engaged = 'engaged';
    case Transferring = 'transferring';
    case ReauthRequired = 'reauth_required';
}
