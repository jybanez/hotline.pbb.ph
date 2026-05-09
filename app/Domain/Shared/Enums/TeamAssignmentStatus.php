<?php

namespace App\Domain\Shared\Enums;

enum TeamAssignmentStatus: string
{
    case Assigned = 'assigned';
    case Requested = 'requested';
    case Accepted = 'accepted';
    case EnRoute = 'en_route';
    case OnScene = 'on_scene';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
