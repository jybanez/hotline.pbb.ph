<?php

namespace Pbb\Sitreps\Viewer;

final class SitrepPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    public static function from(array|string $payload): self
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('SITREP JSON payload must decode to an object.');
            }

            $payload = $decoded;
        }

        return new self(self::normalize($payload));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $section = $this->payload[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalize(array $payload): array
    {
        foreach ([
            'summary',
            'situation',
            'damage',
            'population',
            'actions',
            'needs',
            'gaps',
            'source_snapshot',
            'privacy_redactions',
            'data_quality',
        ] as $key) {
            if (! isset($payload[$key]) || ! is_array($payload[$key])) {
                $payload[$key] = [];
            }
        }

        $payload['status'] = trim((string) ($payload['status'] ?? 'draft')) ?: 'draft';
        $payload['visibility'] = trim((string) ($payload['visibility'] ?? 'private')) ?: 'private';
        $payload['alert_level'] = trim((string) ($payload['alert_level'] ?? 'Normal')) ?: 'Normal';
        $payload['title'] = trim((string) ($payload['title'] ?? 'PBB SITREP')) ?: 'PBB SITREP';

        return $payload;
    }
}
