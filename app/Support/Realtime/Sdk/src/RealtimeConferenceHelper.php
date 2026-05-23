<?php

declare(strict_types=1);

final class RealtimeConferenceHelper
{
    /**
     * @return array<string, int>
     */
    public static function conferenceDefaults(): array
    {
        return [
            'mesh_participant_limit' => 5,
            'warning_threshold' => 4,
        ];
    }

    public static function normalizeCallMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return in_array($normalized, ['audio', 'video'], true) ? $normalized : 'audio';
    }

    public static function enforceParticipantGuardrail(int $count, int $max = 5): void
    {
        if ($count > $max) {
            throw new InvalidArgumentException("Conference participant count {$count} exceeds mesh limit of {$max}.");
        }
    }
}
