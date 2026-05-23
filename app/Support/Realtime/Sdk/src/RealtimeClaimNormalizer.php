<?php

declare(strict_types=1);

final class RealtimeClaimNormalizer
{
    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    public function normalizeClaims(array $claims): array
    {
        foreach (['sub', 'project_code', 'app_code', 'user_id'] as $required) {
            $value = trim((string) ($claims[$required] ?? ''));
            if ($value === '') {
                throw new InvalidArgumentException("Missing required Realtime claim: {$required}.");
            }
            $claims[$required] = $value;
        }

        $claims['display_name'] = $this->nullableString($claims['display_name'] ?? null);
        $claims['email'] = $this->nullableString($claims['email'] ?? null);
        $claims['tenant_id'] = $this->nullableString($claims['tenant_id'] ?? null);
        $claims['org_id'] = $this->nullableString($claims['org_id'] ?? null);
        $claims['workspace_id'] = $this->nullableString($claims['workspace_id'] ?? null);
        $claims['origin'] = $this->nullableString($claims['origin'] ?? null);

        $claims['roles'] = $this->normalizeStringList($claims['roles'] ?? []);
        $claims['capabilities'] = $this->normalizeStringList($claims['capabilities'] ?? []);
        $claims['allowed_rooms'] = $this->normalizeStringList($claims['allowed_rooms'] ?? []);
        $claims['allowed_room_prefixes'] = $this->normalizeStringList($claims['allowed_room_prefixes'] ?? []);
        $claims['attachment_policy'] = $this->normalizeAttachmentPolicy($claims['attachment_policy'] ?? []);

        return $claims;
    }

    /**
     * @param array<int, mixed> $capabilities
     * @return array<int, string>
     */
    public function normalizeCapabilities(array $capabilities): array
    {
        return $this->normalizeStringList($capabilities);
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, int>
     */
    public function normalizeAttachmentPolicy(array $policy): array
    {
        return [
            'max_attachment_count' => max(0, (int) ($policy['max_attachment_count'] ?? 0)),
            'max_attachment_bytes' => max(0, (int) ($policy['max_attachment_bytes'] ?? 0)),
            'max_total_bytes_per_message' => max(0, (int) ($policy['max_total_bytes_per_message'] ?? 0)),
            'chunk_events_per_minute' => max(0, (int) ($policy['chunk_events_per_minute'] ?? 0)),
            'chunk_bytes_per_minute' => max(0, (int) ($policy['chunk_bytes_per_minute'] ?? 0)),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function normalizeStringList(array $values): array
    {
        $result = [];

        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                $result[] = $normalized;
            }
        }

        return array_values(array_unique($result));
    }
}
