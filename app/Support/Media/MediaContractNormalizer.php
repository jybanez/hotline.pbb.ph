<?php

namespace App\Support\Media;

class MediaContractNormalizer
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $payload): array
    {
        if (array_key_exists('type', $payload)) {
            $payload['type'] = self::normalizeType((string) $payload['type']);
        }

        if (array_key_exists('peer_role', $payload)) {
            $payload['peer_role'] = self::normalizePeerRole($payload['peer_role']);
        }

        return $payload;
    }

    public static function normalizeType(string $type): string
    {
        return $type === 'citizen_video' ? 'caller_video' : $type;
    }

    public static function normalizePeerRole(mixed $role): mixed
    {
        return $role === 'citizen' ? 'caller' : $role;
    }
}
