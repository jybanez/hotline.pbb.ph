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
        return $type === 'caller_video' ? 'citizen_video' : $type;
    }

    public static function normalizePeerRole(mixed $role): mixed
    {
        return $role === 'caller' ? 'citizen' : $role;
    }

    public static function typesMatch(string $left, string $right): bool
    {
        return self::normalizeType($left) === self::normalizeType($right);
    }

    /**
     * @return array<int, string>
     */
    public static function citizenVideoTypes(): array
    {
        return ['caller_video', 'citizen_video'];
    }
}
