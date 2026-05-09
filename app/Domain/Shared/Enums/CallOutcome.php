<?php

namespace App\Domain\Shared\Enums;

enum CallOutcome: string
{
    case Answered = 'answered';
    case TimedOut = 'timed_out';
    case DeclinedByOperator = 'declined_by_operator';
    case CancelledByCaller = 'cancelled_by_caller';
    case EndedByOperator = 'ended_by_operator';
    case EndedByCaller = 'ended_by_caller';
}
