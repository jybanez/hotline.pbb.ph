<?php

namespace Pbb\Sitreps\Consolidation;

final class SitrepNormalizer
{
    /**
     * @param array<string, mixed> $sitrep
     * @return array{normalized: array<string, mixed>|null, issues: SitrepValidationIssue[]}
     */
    public function normalize(array $sitrep, int $sourceIndex = 0): array
    {
        $issues = [];
        $hubSnapshot = $this->getArray($sitrep, 'source_snapshot.hub_node.snapshot');
        $deployment = $this->stringValue($hubSnapshot['deployment'] ?? null);
        $hubId = $this->stringValue($hubSnapshot['hub_id'] ?? null);

        foreach (['period_started_at', 'period_ended_at'] as $field) {
            if ($this->stringValue($sitrep[$field] ?? null) === null) {
                $issues[] = new SitrepValidationIssue(
                    'error',
                    'missing_required_field',
                    sprintf('SITREP is missing required field "%s".', $field),
                    $field,
                    null,
                    $sourceIndex,
                );
            }
        }

        if ($deployment === null) {
            $issues[] = new SitrepValidationIssue(
                'error',
                'missing_source_deployment',
                'SITREP is missing source deployment metadata.',
                'source_snapshot.hub_node.snapshot.deployment',
                null,
                $sourceIndex,
            );
        }

        if ($hubId === null) {
            $issues[] = new SitrepValidationIssue(
                'error',
                'missing_source_hub_id',
                'SITREP is missing PBB HUB HQ hub_id metadata.',
                'source_snapshot.hub_node.snapshot.hub_id',
                null,
                $sourceIndex,
            );
        }

        if ($this->hasErrors($issues)) {
            return [
                'normalized' => null,
                'issues' => $issues,
            ];
        }

        $normalized = [
            'source_index' => $sourceIndex,
            'source_hub_id' => $hubId,
            'source_hub_name' => $this->stringValue($hubSnapshot['name'] ?? null) ?? $this->stringValue($sitrep['coverage_area'] ?? null) ?? $hubId,
            'source_deployment' => $deployment,
            'relay_hub_id' => $this->stringValue($hubSnapshot['relay_hub_id'] ?? null),
            'sequence_number' => $sitrep['sequence_number'] ?? null,
            'title' => $this->stringValue($sitrep['title'] ?? null),
            'coverage_area' => $this->stringValue($sitrep['coverage_area'] ?? null),
            'period_started_at' => $this->stringValue($sitrep['period_started_at'] ?? null),
            'period_ended_at' => $this->stringValue($sitrep['period_ended_at'] ?? null),
            'generated_at' => $this->stringValue($sitrep['generated_at'] ?? null),
            'alert_level' => $this->normalizeAlertLevel($sitrep['alert_level'] ?? null),
            'payload_hash' => hash('sha256', json_encode($sitrep, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'payload' => $sitrep,
        ];

        return [
            'normalized' => $normalized,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function getArray(array $payload, string $path): array
    {
        $value = $payload;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [];
            }

            $value = $value[$segment];
        }

        return is_array($value) ? $value : [];
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function normalizeAlertLevel(mixed $value): string
    {
        $level = strtolower(trim((string) $value));

        return match ($level) {
            'critical' => 'Critical',
            'elevated' => 'Elevated',
            default => 'Normal',
        };
    }

    /**
     * @param SitrepValidationIssue[] $issues
     */
    private function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->severity === 'error') {
                return true;
            }
        }

        return false;
    }
}
