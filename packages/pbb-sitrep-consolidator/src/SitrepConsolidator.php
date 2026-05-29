<?php

namespace Pbb\Sitreps\Consolidation;

final class SitrepConsolidator
{
    public function __construct(
        private readonly SitrepNormalizer $normalizer = new SitrepNormalizer(),
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $sitreps
     * @return array{groups: array<string, array<int, array<string, mixed>>>, issues: array<int, array<string, mixed>>}
     */
    public function groupByDeployment(array $sitreps): array
    {
        $groups = [];
        $issues = [];

        foreach ($sitreps as $index => $sitrep) {
            $result = $this->normalizer->normalize($sitrep, $index);

            foreach ($result['issues'] as $issue) {
                $issues[] = $issue->toArray();
            }

            if ($result['normalized'] === null) {
                continue;
            }

            $groups[$result['normalized']['source_deployment']][] = $result['normalized'];
        }

        ksort($groups);

        return [
            'groups' => $groups,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $sitreps
     * @param array<string, mixed> $context
     */
    public function consolidate(array $sitreps, array $context): SitrepConsolidationResult
    {
        $normalized = [];
        $issues = [];

        foreach ($sitreps as $index => $sitrep) {
            $payload = isset($sitrep['payload']) && is_array($sitrep['payload']) ? $sitrep['payload'] : $sitrep;
            $result = $this->normalizer->normalize($payload, $index);
            $issues = array_merge($issues, $result['issues']);

            if ($result['normalized'] !== null) {
                $normalized[] = $result['normalized'];
            }
        }

        if ($this->hasErrors($issues)) {
            return new SitrepConsolidationResult(false, null, $issues, $this->sourceIndex($normalized));
        }

        if ($normalized === []) {
            return new SitrepConsolidationResult(false, null, [
                new SitrepValidationIssue('error', 'empty_source_batch', 'No valid SITREPs were provided.'),
            ]);
        }

        $deployments = array_values(array_unique(array_map(
            static fn (array $sitrep): string => (string) $sitrep['source_deployment'],
            $normalized,
        )));

        if (count($deployments) > 1) {
            return new SitrepConsolidationResult(false, null, [
                new SitrepValidationIssue(
                    'error',
                    'mixed_source_deployment',
                    'Source SITREPs must have the same hub deployment level before consolidation.',
                    'source_snapshot.hub_node.snapshot.deployment',
                    $deployments,
                ),
            ], $this->sourceIndex($normalized));
        }

        $duplicateHubIds = $this->duplicateSourceHubIds($normalized);
        if ($duplicateHubIds !== []) {
            return new SitrepConsolidationResult(false, null, [
                new SitrepValidationIssue(
                    'error',
                    'duplicate_source_hub',
                    'Source SITREP batches must contain at most one report per source hub. Stage latest-by-hub before consolidation.',
                    'source_snapshot.hub_node.snapshot.hub_id',
                    $duplicateHubIds,
                ),
            ], $this->sourceIndex($normalized));
        }

        $sourceIndex = $this->sourceIndex($normalized);
        $sitrep = [
            'title' => $this->title($context, $deployments[0]),
            'coverage_area' => (string) ($context['coverage_area'] ?? $context['target_hub_name'] ?? 'Consolidated Coverage Area'),
            'coverage_level' => (string) ($context['target_level'] ?? 'consolidated'),
            'period_started_at' => (string) ($context['period_started_at'] ?? $normalized[0]['period_started_at']),
            'period_ended_at' => (string) ($context['period_ended_at'] ?? $normalized[0]['period_ended_at']),
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'alert_level' => $this->highestAlertLevel($normalized),
            'summary' => $this->mergeSummary($normalized, $context),
            'situation' => $this->mergeSituation($normalized),
            'damage' => $this->mergeSectionItems($normalized, 'damage'),
            'population' => $this->mergePopulation($normalized),
            'actions' => $this->mergeActions($normalized),
            'needs' => $this->mergeNeeds($normalized),
            'gaps' => $this->mergeSectionItems($normalized, 'gaps'),
            'source_snapshot' => [
                'generation' => [
                    'type' => 'consolidated',
                    'sdk' => 'pbb-sitrep-consolidator',
                    'sdk_version' => '0.1.0',
                    'merge_rule_version' => 1,
                ],
                'target' => [
                    'hub_id' => $context['target_hub_id'] ?? null,
                    'name' => $context['target_hub_name'] ?? null,
                    'level' => $context['target_level'] ?? null,
                ],
                'source_deployment' => $deployments[0],
                'source_sitreps' => $sourceIndex,
            ],
            'privacy_redactions' => [
                'inherited' => true,
                'note' => 'Consolidated from generated SITREP payloads; source redaction state is preserved by provenance.',
            ],
            'data_quality' => [
                'source_sitrep_count' => count($normalized),
                'source_hub_count' => count($sourceIndex),
                'warnings' => array_map(
                    static fn (SitrepValidationIssue $issue): array => $issue->toArray(),
                    array_values(array_filter($issues, static fn (SitrepValidationIssue $issue): bool => $issue->severity === 'warning')),
                ),
            ],
        ];

        return new SitrepConsolidationResult(true, $sitrep, $issues, $sourceIndex);
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     * @return array<int, string>
     */
    private function duplicateSourceHubIds(array $normalized): array
    {
        $counts = [];

        foreach ($normalized as $source) {
            $hubId = (string) $source['source_hub_id'];
            $counts[$hubId] = ($counts[$hubId] ?? 0) + 1;
        }

        return array_map(
            static fn (int|string $hubId): string => (string) $hubId,
            array_values(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1))),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     * @return array<int, array<string, mixed>>
     */
    private function sourceIndex(array $normalized): array
    {
        $sources = [];

        foreach ($normalized as $source) {
            $sources[$source['source_hub_id']] = [
                'source_hub_id' => $source['source_hub_id'],
                'source_hub_name' => $source['source_hub_name'],
                'source_deployment' => $source['source_deployment'],
                'relay_hub_id' => $source['relay_hub_id'],
                'sequence_number' => $source['sequence_number'],
                'title' => $source['title'],
                'period_started_at' => $source['period_started_at'],
                'period_ended_at' => $source['period_ended_at'],
                'generated_at' => $source['generated_at'],
                'hash' => $source['payload_hash'],
                'status' => 'accepted',
            ];
        }

        ksort($sources);

        return array_values($sources);
    }

    private function title(array $context, string $sourceDeployment): string
    {
        $level = $context['target_level'] ?? 'Consolidated';
        $area = $context['coverage_area'] ?? $context['target_hub_name'] ?? ucfirst($sourceDeployment).' Rollup';

        return sprintf('%s SITREP - %s', ucfirst((string) $level), (string) $area);
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     */
    private function highestAlertLevel(array $normalized): string
    {
        $rank = ['Normal' => 1, 'Elevated' => 2, 'Critical' => 3];
        $highest = 'Normal';

        foreach ($normalized as $source) {
            $level = (string) $source['alert_level'];

            if (($rank[$level] ?? 1) > $rank[$highest]) {
                $highest = $level;
            }
        }

        return $highest;
    }

    private function mergeSummary(array $normalized, array $context): array
    {
        $metrics = [];
        $statusCounts = [];

        foreach ($normalized as $source) {
            $summary = $source['payload']['summary'] ?? [];

            foreach (($summary['supporting_metrics'] ?? []) as $key => $value) {
                if (is_numeric($value)) {
                    $metrics[$key] = ($metrics[$key] ?? 0) + (int) $value;
                }
            }

            foreach (($summary['status_counts'] ?? []) as $key => $value) {
                if (is_numeric($value)) {
                    $statusCounts[$key] = ($statusCounts[$key] ?? 0) + (int) $value;
                }
            }
        }

        ksort($metrics);
        ksort($statusCounts);

        return [
            'headline' => sprintf(
                'Consolidated %d %s SITREP%s for %s.',
                count($normalized),
                $normalized[0]['source_deployment'],
                count($normalized) === 1 ? '' : 's',
                (string) ($context['coverage_area'] ?? $context['target_hub_name'] ?? 'the target coverage area'),
            ),
            'posture_label' => $this->highestAlertLevel($normalized),
            'supporting_metrics' => $metrics,
            'status_counts' => $statusCounts,
            'source_hub_count' => count($normalized),
        ];
    }

    private function mergeSituation(array $normalized): array
    {
        return [
            'narrative' => sprintf('This consolidated SITREP includes %d source report%s.', count($normalized), count($normalized) === 1 ? '' : 's'),
            'source_hubs' => array_map(static fn (array $source): string => (string) $source['source_hub_name'], $normalized),
        ];
    }

    private function mergePopulation(array $normalized): array
    {
        $numericTotal = 0;
        $recordCount = 0;

        foreach ($normalized as $source) {
            $population = $source['payload']['population'] ?? [];
            $numericTotal += (int) ($population['numeric_total'] ?? 0);
            $recordCount += (int) ($population['record_count'] ?? 0);
        }

        return [
            'numeric_total' => $numericTotal,
            'record_count' => $recordCount,
        ];
    }

    private function mergeActions(array $normalized): array
    {
        $totalAssignments = 0;

        foreach ($normalized as $source) {
            foreach (($source['payload']['actions']['deployment_groups'] ?? []) as $group) {
                $totalAssignments += (int) ($group['total_assignments'] ?? 0);
            }
        }

        return [
            'total_assignments' => $totalAssignments,
        ];
    }

    private function mergeNeeds(array $normalized): array
    {
        $items = [];
        $total = 0;

        foreach ($normalized as $source) {
            foreach (($source['payload']['needs']['items'] ?? []) as $item) {
                $resource = (string) ($item['resource'] ?? $item['name'] ?? 'Resource');
                $category = (string) ($item['category'] ?? 'Uncategorized');
                $key = strtolower($category.'|'.$resource);
                $quantity = (int) ($item['quantity_requested'] ?? $item['quantity'] ?? 0);
                $total += $quantity;

                $items[$key] ??= [
                    'resource' => $resource,
                    'category' => $category,
                    'quantity_requested' => 0,
                    'sources' => [],
                ];
                $items[$key]['quantity_requested'] += $quantity;
                $items[$key]['sources'][] = [
                    'source_hub_id' => $source['source_hub_id'],
                    'quantity_requested' => $quantity,
                ];
            }
        }

        ksort($items);

        return [
            'total_quantity_requested' => $total,
            'items' => array_values($items),
        ];
    }

    private function mergeSectionItems(array $normalized, string $section): array
    {
        $items = [];

        foreach ($normalized as $source) {
            foreach (($source['payload'][$section]['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $item['source_hub_id'] = $source['source_hub_id'];
                    $items[] = $item;
                }
            }
        }

        return [
            'items' => $items,
        ];
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
