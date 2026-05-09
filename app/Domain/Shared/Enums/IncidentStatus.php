<?php

namespace App\Domain\Shared\Enums;

enum IncidentStatus: string
{
    case Active = 'Active';
    case Deferred = 'Deferred';
    case Discarded = 'Discarded';
    case Resolved = 'Resolved';
}
