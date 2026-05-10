<?php

namespace App\Domain\Shared\Enums;

enum CallOutcome: string
{
    case Answered = 'answered';
    case TimedOut = 'timed_out';
    case DeclinedByOperator = 'declined_by_operator';
    case CancelledByCitizen = 'cancelled_by_citizen';
    /** @deprecated Use CancelledByCitizen. Kept temporarily for existing rows and legacy clients. */
    case CancelledByCaller = 'cancelled_by_caller';
    case EndedByOperator = 'ended_by_operator';
    case EndedByCitizen = 'ended_by_citizen';
    /** @deprecated Use EndedByCitizen. Kept temporarily for existing rows and legacy clients. */
    case EndedByCaller = 'ended_by_caller';

    public function canonical(): self
    {
        return match ($this) {
            self::CancelledByCaller => self::CancelledByCitizen,
            self::EndedByCaller => self::EndedByCitizen,
            default => $this,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $outcome): string => $outcome->value,
            self::cases(),
        );
    }
}
