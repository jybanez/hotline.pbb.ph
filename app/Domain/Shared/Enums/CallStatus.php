<?php

namespace App\Domain\Shared\Enums;

enum CallStatus: string
{
    case Calling = 'calling';
    case InProgress = 'in_progress';
    case Ended = 'ended';
}
