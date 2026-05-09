<?php

namespace App\Domain\Shared\Enums;

enum AlertLevel: string
{
    case Normal = 'Normal';
    case Elevated = 'Elevated';
    case Critical = 'Critical';

    public function description(): string
    {
        return match ($this) {
            self::Normal => 'Standard barangay operations are in effect.',
            self::Elevated => 'Heightened readiness is in effect due to increased local risk.',
            self::Critical => 'Critical local response conditions are active. Immediate coordination is required.',
        };
    }
}
