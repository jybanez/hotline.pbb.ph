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
        $period = $this->period($normalized, $context);
        $alertLevel = $this->highestAlertLevel($normalized);
        $sitrep = [
            'schema_version' => 2,
            'title' => $this->title($context, $deployments[0]),
            'coverage_area' => (string) ($context['coverage_area'] ?? $context['target_hub_name'] ?? 'Consolidated Coverage Area'),
            'coverage_level' => (string) ($context['target_level'] ?? 'consolidated'),
            'location_count' => count($normalized),
            'period_started_at' => $period['started_at'],
            'period_ended_at' => $period['ended_at'],
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'status' => (string) ($context['status'] ?? 'draft'),
            'visibility' => (string) ($context['visibility'] ?? 'private'),
            'alert_level' => $alertLevel,
            'summary' => $this->sectionWithItems($normalized, 'summary', $this->mergeSummary($normalized, $context)),
            'situation' => $this->sectionWithItems($normalized, 'situation', $this->mergeSituation($normalized)),
            'damage' => $this->sectionWithItems($normalized, 'damage', $this->mergeDamage($normalized)),
            'population' => $this->sectionWithItems($normalized, 'population', $this->mergePopulation($normalized)),
            'actions' => $this->sectionWithItems($normalized, 'actions', $this->mergeActions($normalized)),
            'needs' => $this->sectionWithItems($normalized, 'needs', $this->mergeNeeds($normalized)),
            'gaps' => $this->sectionWithItems($normalized, 'gaps', $this->mergeGaps($normalized)),
            'source_snapshot' => $this->sourceSnapshotSection($normalized, $context, $deployments[0], $sourceIndex),
            'privacy_redactions' => [
                'inherited' => true,
                'note' => 'Consolidated from generated SITREP payloads; source redaction state is preserved by provenance.',
            ],
            'data_quality' => $this->sectionWithItems($normalized, 'data_quality', [
                'global_note' => 'Consolidated from generated SITREP payloads. Population figures and other numeric source fields may overlap and should be verified before operational use.',
                'source_sitrep_count' => count($normalized),
                'source_hub_count' => count($sourceIndex),
                'warnings' => array_map(
                    static fn (SitrepValidationIssue $issue): array => $issue->toArray(),
                    array_values(array_filter($issues, static fn (SitrepValidationIssue $issue): bool => $issue->severity === 'warning')),
                ),
            ]),
        ];

        return new SitrepConsolidationResult(true, $sitrep, $issues, $sourceIndex);
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     * @param array<string, mixed> $context
     * @return array{started_at: string, ended_at: string}
     */
    private function period(array $normalized, array $context): array
    {
        return [
            'started_at' => (string) ($context['period_started_at'] ?? $this->periodBound($normalized, 'period_started_at', 'earliest') ?? $normalized[0]['period_started_at']),
            'ended_at' => (string) ($context['period_ended_at'] ?? $this->periodBound($normalized, 'period_ended_at', 'latest') ?? $normalized[0]['period_ended_at']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     */
    private function periodBound(array $normalized, string $field, string $direction): ?string
    {
        $selectedText = null;
        $selectedTimestamp = null;

        foreach ($normalized as $source) {
            if (! is_string($source[$field] ?? null) || trim($source[$field]) === '') {
                continue;
            }

            $text = $source[$field];

            try {
                $timestamp = (new \DateTimeImmutable($text))->getTimestamp();
            } catch (\Throwable) {
                continue;
            }

            if (
                $selectedTimestamp === null
                || ($direction === 'earliest' && $timestamp < $selectedTimestamp)
                || ($direction === 'latest' && $timestamp > $selectedTimestamp)
            ) {
                $selectedTimestamp = $timestamp;
                $selectedText = $text;
            }
        }

        return $selectedText;
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     * @param array<string, mixed> $mergedRollup
     * @return array{rollup: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function sectionWithItems(array $normalized, string $section, array $mergedRollup): array
    {
        $items = [];

        foreach ($normalized as $source) {
            $items[] = [
                'location' => $this->sourceLocation($source),
                'data' => $this->sourceSection($source, $section),
            ];
        }

        return [
            'rollup' => count($normalized) === 1 ? ($items[0]['data'] ?? $mergedRollup) : $mergedRollup,
            'items' => $items,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $sourceIndex
     * @return array{rollup: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function sourceSnapshotSection(array $normalized, array $context, string $deployment, array $sourceIndex): array
    {
        $items = [];

        foreach ($normalized as $source) {
            $items[] = [
                'location' => $this->sourceLocation($source),
                'data' => $this->sourceSection($source, 'source_snapshot'),
            ];
        }

        return [
            'rollup' => [
                'generation' => [
                    'type' => 'consolidated',
                    'sdk' => 'pbb-sitrep-consolidator',
                    'sdk_version' => '0.1.0',
                    'merge_rule_version' => 1,
                    'prepared_by_label' => (string) ($context['prepared_by_label'] ?? 'System Generated'),
                ],
                'target' => [
                    'hub_id' => $context['target_hub_id'] ?? null,
                    'name' => $context['target_hub_name'] ?? null,
                    'level' => $context['target_level'] ?? null,
                ],
                'hub_node' => $this->targetHubNode($context),
                'source_deployment' => $deployment,
                'hub_nodes' => $this->sourceHubNodes($normalized),
                'source_sitreps' => $sourceIndex,
                'incident_coordinates' => $this->mergeIncidentCoordinates($normalized),
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceLocation(array $source): array
    {
        return [
            'id' => $source['source_hub_id'],
            'name' => $source['source_hub_name'],
            'deployment' => $source['source_deployment'],
            'relay_hub_id' => $source['relay_hub_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSection(array $source, string $section): array
    {
        $data = $source['payload'][$section] ?? [];

        if (! is_array($data)) {
            return [];
        }

        return isset($data['rollup']) && is_array($data['rollup'])
            ? $data['rollup']
            : $data;
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     * @return array<int, array<string, mixed>>
     */
    private function sourceHubNodes(array $normalized): array
    {
        $hubNodes = [];

        foreach ($normalized as $source) {
            if (isset($source['source_hub_node']) && is_array($source['source_hub_node'])) {
                $hubNodes[] = $source['source_hub_node'];
            }
        }

        return $hubNodes;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function targetHubNode(array $context): array
    {
        if (isset($context['target_hub_node']) && is_array($context['target_hub_node'])) {
            return $context['target_hub_node'];
        }

        return [
            'available' => ($context['target_hub_id'] ?? null) !== null || ($context['target_hub_name'] ?? null) !== null || ($context['target_level'] ?? null) !== null,
            'source' => 'consolidation_context',
            'snapshot' => [
                'hub_id' => $context['target_hub_id'] ?? null,
                'name' => $context['target_hub_name'] ?? null,
                'deployment' => $context['target_level'] ?? null,
            ],
        ];
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
        $highest = 'Normal';

        foreach ($normalized as $source) {
            $highest = $this->highestAlertLabel([$highest, (string) $source['alert_level']]);
        }

        return $highest;
    }

    /**
     * @param array<int, string> $levels
     */
    private function highestAlertLabel(array $levels): string
    {
        $rank = ['Normal' => 1, 'Elevated' => 2, 'Critical' => 3];
        $highest = 'Normal';

        foreach ($levels as $level) {
            $normalized = ucfirst(strtolower(trim($level)));

            if (($rank[$normalized] ?? 1) > $rank[$highest]) {
                $highest = $normalized;
            }
        }

        return $highest;
    }

    private function mergeSummary(array $normalized, array $context): array
    {
        $metrics = [];
        $statusCounts = [];
        $highestAlert = $this->highestAlertLevel($normalized);
        $gapCards = [];
        $accomplishmentCards = [];
        $priorityWatchItems = [];
        $decisionPoints = [];

        foreach ($normalized as $source) {
            $summary = $this->sourceSection($source, 'summary');
            $situation = $this->sourceSection($source, 'situation');

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

            $this->mergeCardRows($gapCards, $summary['gap_cards'] ?? [], $source);
            $this->mergeCardRows($accomplishmentCards, $summary['accomplishment_cards'] ?? [], $source);
            $this->mergeWatchItems($priorityWatchItems, $summary['priority_watch_items'] ?? [], $source);
            $this->mergeDecisionRows($decisionPoints, $summary['decision_points'] ?? [], $source);
            $this->mergeDecisionRows($decisionPoints, $situation['decision_points'] ?? [], $source);
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
            'posture' => strtolower($highestAlert),
            'posture_label' => $highestAlert,
            'posture_reason' => 'Consolidated posture reflects the highest alert level among accepted source SITREPs.',
            'primary_concern' => sprintf('%d source hub%s reporting', count($normalized), count($normalized) === 1 ? '' : 's'),
            'hotspot_area' => (string) ($context['coverage_area'] ?? $context['target_hub_name'] ?? 'Consolidated coverage area'),
            'hotspot_note' => 'Review source SITREPs for hub-level concentration and incident details.',
            'confidence_note' => 'This rollup preserves source provenance; operational interpretation remains with the receiving LGU or support organization.',
            'gap_cards' => array_values($gapCards),
            'accomplishment_cards' => array_values($accomplishmentCards),
            'executive_cards' => array_merge(array_values($gapCards), array_values($accomplishmentCards), [
                [
                    'label' => 'Source Hubs',
                    'value' => (string) count($normalized),
                    'note' => 'Accepted latest SITREPs included in this consolidation.',
                ],
                [
                    'label' => 'Highest Alert',
                    'value' => $highestAlert,
                    'note' => 'Highest alert level among included source SITREPs.',
                ],
                [
                    'label' => 'Resource Units',
                    'value' => (string) ($metrics['resource_need_units'] ?? 0),
                    'note' => 'Summed requested resource units reported by sources.',
                ],
            ]),
            'priority_watch_items' => array_values($priorityWatchItems),
            'decision_points' => array_values($decisionPoints),
            'supporting_metrics' => $metrics,
            'status_counts' => $statusCounts,
            'source_hub_count' => count($normalized),
        ];
    }

    private function mergeSituation(array $normalized): array
    {
        $sourceNames = array_map(static fn (array $source): string => (string) $source['source_hub_name'], $normalized);
        $locations = [];
        $incidentTypes = [];
        $concernGroups = [];
        $currentPicture = [
            'open_reports' => 0,
            'active_reports' => 0,
            'deferred_reports' => 0,
            'current_assignments' => 0,
            'current_resource_units' => 0,
        ];

        foreach ($normalized as $source) {
            $situation = $this->sourceSection($source, 'situation');
            $summary = $this->sourceSection($source, 'summary');

            $picture = is_array($situation['current_operating_picture'] ?? null) ? $situation['current_operating_picture'] : [];
            $metrics = is_array($summary['supporting_metrics'] ?? null) ? $summary['supporting_metrics'] : [];
            $statusCounts = is_array($summary['status_counts'] ?? null) ? $summary['status_counts'] : [];
            $currentPicture['open_reports'] += (int) ($picture['open_reports'] ?? $metrics['active_at_close'] ?? $metrics['total_incidents'] ?? 0);
            $currentPicture['active_reports'] += (int) ($picture['active_reports'] ?? $statusCounts['Active'] ?? $statusCounts['active'] ?? 0);
            $currentPicture['deferred_reports'] += (int) ($picture['deferred_reports'] ?? $statusCounts['Deferred'] ?? $statusCounts['deferred'] ?? 0);
            $currentPicture['current_assignments'] += (int) ($picture['current_assignments'] ?? $metrics['team_assignments'] ?? 0);
            $currentPicture['current_resource_units'] += (int) ($picture['current_resource_units'] ?? $metrics['resource_need_units'] ?? 0);

            foreach (($situation['locations'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $area = $this->text($row['area'] ?? $source['source_hub_name'], 'Unknown');
                $locations[$area] ??= [
                    'area' => $area,
                    'count' => 0,
                    'alert_level' => (string) $source['alert_level'],
                    'source_hubs' => [],
                ];
                $locations[$area]['count'] += (int) ($row['count'] ?? $row['report_count'] ?? 0);
                $locations[$area]['source_hubs'][] = $source['source_hub_name'];
                $locations[$area]['alert_level'] = $this->highestAlertLabel([
                    (string) ($locations[$area]['alert_level'] ?? 'Normal'),
                    (string) $source['alert_level'],
                ]);
            }

            foreach (($situation['incident_types'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $type = $this->text($row['type'] ?? $row['name'] ?? null, 'Unclassified');
                $incidentTypes[$type] ??= [
                    'type' => $type,
                    'count' => 0,
                    'source_hubs' => [],
                ];
                $incidentTypes[$type]['count'] += (int) ($row['count'] ?? $row['mentions'] ?? 0);
                $incidentTypes[$type]['source_hubs'][] = $source['source_hub_name'];
            }

            foreach (($situation['concern_groups'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $concern = $this->text($row['concern'] ?? null, 'Current concern');
                $concernGroups[$concern] ??= [
                    'concern' => $concern,
                    'open_reports' => 0,
                    'areas' => [],
                    'main_signals' => [],
                    'current_assignments' => 0,
                    'resource_units' => 0,
                ];
                $concernGroups[$concern]['open_reports'] += (int) ($row['open_reports'] ?? 0);
                $concernGroups[$concern]['current_assignments'] += (int) ($row['current_assignments'] ?? 0);
                $concernGroups[$concern]['resource_units'] += (int) ($row['resource_units'] ?? 0);
                $concernGroups[$concern]['areas'] = array_merge(
                    $concernGroups[$concern]['areas'],
                    array_values(array_filter((array) ($row['areas'] ?? []), 'is_scalar')),
                );
                if (($row['main_signals'] ?? '') !== '') {
                    $concernGroups[$concern]['main_signals'][] = (string) $row['main_signals'];
                }
            }
        }

        $locationRows = $this->sortedRows(array_values(array_map(function (array $row): array {
            $row['source_hubs'] = array_values(array_unique($row['source_hubs']));

            return $row;
        }, $locations)), 'count');
        $incidentTypeRows = $this->sortedRows(array_values(array_map(function (array $row): array {
            $row['source_hubs'] = array_values(array_unique($row['source_hubs']));
            $row['location_count'] = count($row['source_hubs']);

            return $row;
        }, $incidentTypes)), 'count');
        $concernRows = $this->sortedRows(array_values(array_map(function (array $row): array {
            $row['areas'] = array_values(array_unique($row['areas']));
            $row['main_signals'] = implode('; ', array_values(array_unique(array_filter($row['main_signals']))));

            return $row;
        }, $concernGroups)), 'open_reports');

        return [
            'executive_assessment' => sprintf(
                '%d active/deferred incident reports remain open across %s. Leading current classifications include %s. Current pressure includes %d active, %d deferred, %d in-progress assignments, and %d requested resource units across %d source hubs.',
                $currentPicture['open_reports'],
                $this->areaPhrase($locationRows),
                $this->typePhrase($incidentTypeRows),
                $currentPicture['active_reports'],
                $currentPicture['deferred_reports'],
                $currentPicture['current_assignments'],
                $currentPicture['current_resource_units'],
                count($normalized),
            ),
            'narrative' => sprintf(
                'This consolidated SITREP includes %d source report%s covering %d open/deferred reports across %d source hub%s.',
                count($normalized),
                count($normalized) === 1 ? '' : 's',
                $currentPicture['open_reports'],
                count($normalized),
                count($normalized) === 1 ? '' : 's',
            ),
            'current_operating_picture' => $currentPicture,
            'source_hubs' => $sourceNames,
            'areas_of_concern' => $sourceNames,
            'locations' => $locationRows,
            'incident_types' => $incidentTypeRows,
            'concern_groups' => $concernRows,
            'decision_points' => $this->consolidatedDecisionPoints($currentPicture, $locationRows, $incidentTypeRows, $concernRows),
        ];
    }

    private function mergePopulation(array $normalized): array
    {
        $numericTotal = 0;
        $recordCount = 0;
        $citizensAssisted = 0;
        $peopleAtRisk = $this->summaryCardNumericTotal($normalized, 'People at Risk');
        $peopleHelped = $this->summaryCardNumericTotal($normalized, 'People Helped');
        $groups = [];

        foreach ($normalized as $source) {
            $population = $this->sourceSection($source, 'population');
            $numericTotal += (int) ($population['numeric_total'] ?? 0);
            $recordCount += (int) ($population['record_count'] ?? 0);
            $citizensAssisted += (int) ($population['citizens_assisted'] ?? $population['callers_assisted'] ?? 0);

            foreach (($population['population_groups'] ?? []) as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $signal = $this->text($group['population_signal'] ?? $group['label'] ?? $group['title'] ?? null, 'Population signal');
                $key = strtolower($signal);
                $groups[$key] ??= [
                    'population_signal' => $signal,
                    'reports' => 0,
                    'people_or_families' => '',
                    'notes' => [],
                    'breakdowns' => [],
                    'numeric_total' => 0,
                    'family_total' => 0,
                    'people_total' => 0,
                    'source_hubs' => [],
                    'source_values' => [],
                ];

                $reports = (int) ($group['reports'] ?? $group['count'] ?? 0);
                $peopleOrFamilies = trim((string) ($group['people_or_families'] ?? $group['people_families'] ?? $group['value'] ?? ''));
                $peopleFamilyCounts = $this->peopleFamilyCounts($peopleOrFamilies);
                $numericValue = $peopleFamilyCounts['people'] > 0
                    ? $peopleFamilyCounts['people']
                    : ($this->leadingNumber($peopleOrFamilies) ?? $reports);
                $groups[$key]['reports'] += $reports;
                $groups[$key]['numeric_total'] += $numericValue;
                $groups[$key]['family_total'] += $peopleFamilyCounts['families'];
                $groups[$key]['people_total'] += $peopleFamilyCounts['people'];
                $groups[$key]['source_hubs'][] = $source['source_hub_name'];
                $groups[$key]['source_values'][] = [
                    'source_hub_id' => $source['source_hub_id'],
                    'source_hub_name' => $source['source_hub_name'],
                    'reports' => $reports,
                    'people_or_families' => $peopleOrFamilies,
                ];

                $note = trim((string) ($group['notes'] ?? $group['note'] ?? ''));
                if ($note !== '') {
                    $groups[$key]['notes'][] = $note;
                }

                foreach (($group['breakdowns'] ?? []) as $breakdown) {
                    if (! is_array($breakdown)) {
                        continue;
                    }

                    $breakdownLabel = $this->text($breakdown['breakdown'] ?? $breakdown['label'] ?? null, 'Breakdown');
                    $breakdownKey = strtolower($breakdownLabel);
                    $groups[$key]['breakdowns'][$breakdownKey] ??= [
                        'breakdown' => $breakdownLabel,
                        'count' => 0,
                        'source_hubs' => [],
                    ];
                    $breakdownCount = (int) ($breakdown['count'] ?? 0);
                    $groups[$key]['breakdowns'][$breakdownKey]['count'] += $breakdownCount;
                    if ($breakdownCount > 0) {
                        $groups[$key]['breakdowns'][$breakdownKey]['source_hubs'][] = $source['source_hub_name'];
                    }
                }
            }
        }

        $populationGroups = $this->sortedRows(array_values(array_map(function (array $group): array {
            $total = $group['numeric_total'];
            if ((int) $group['family_total'] > 0 || (int) $group['people_total'] > 0) {
                $group['people_or_families'] = $this->joinParts([
                    (int) $group['family_total'] > 0 ? sprintf('%d %s', (int) $group['family_total'], (int) $group['family_total'] === 1 ? 'family' : 'families') : null,
                    (int) $group['people_total'] > 0 ? sprintf('%d people', (int) $group['people_total']) : null,
                ], ' / ');
            } else {
                $displayTotal = (float) $total === floor((float) $total) ? (string) (int) $total : (string) $total;
                $group['people_or_families'] = $displayTotal.' '.((float) $total === 1.0 ? 'person' : 'people');
            }
            $group['notes'] = implode('; ', array_slice(array_values(array_unique(array_filter($group['notes']))), 0, 3));
            $group['breakdowns'] = array_values(array_filter(
                $this->sortedRows(array_map(function (array $breakdown): array {
                    $breakdown['source_hubs'] = array_values(array_unique($breakdown['source_hubs']));
                    $breakdown['location_count'] = count($breakdown['source_hubs']);

                    return $breakdown;
                }, array_values($group['breakdowns'])), 'count'),
                static fn (array $row): bool => (int) ($row['count'] ?? 0) > 0,
            ));
            $group['source_hubs'] = array_values(array_unique($group['source_hubs']));
            $group['location_count'] = count($group['source_hubs']);
            unset($group['numeric_total'], $group['family_total'], $group['people_total']);

            return $group;
        }, $groups)), 'reports');

        return [
            'numeric_total' => $numericTotal,
            'people_at_risk' => $peopleAtRisk,
            'citizens_assisted' => $peopleHelped > 0 ? $peopleHelped : $citizensAssisted,
            'record_count' => $recordCount,
            'population_groups' => $populationGroups,
            'numeric_total_note' => 'Summed from source SITREPs. Population fields may overlap across source systems and should not be treated as a verified affected-person total without validation.',
            'empty_state' => 'No detailed population roster is included in this consolidated SITREP.',
            'confidence_note' => 'Consolidator preserves source totals for planning awareness; validation remains app-owned.',
        ];
    }

    private function mergeActions(array $normalized): array
    {
        $totalAssignments = 0;
        $groups = [];
        $timingRows = [];

        foreach ($normalized as $source) {
            $actions = $this->sourceSection($source, 'actions');
            foreach (($actions['deployment_groups'] ?? []) as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $category = $this->text($group['category'] ?? null, 'Source SITREPs');
                $team = $this->text($group['team'] ?? null, 'Consolidated Sources');
                $key = strtolower($category.'|'.$team);
                $groups[$key] ??= [
                    'category' => $category,
                    'team' => $team,
                    'incident_ids' => [],
                    'status_counts' => [
                        'other' => 0,
                        'requested' => 0,
                        'assigned' => 0,
                        'accepted' => 0,
                        'en_route' => 0,
                        'on_scene' => 0,
                        'completed' => 0,
                        'cancelled' => 0,
                    ],
                    'reports_covered' => 0,
                    'total_assignments' => 0,
                    'source_hubs' => [],
                ];

                $assignmentCount = (int) ($group['total_assignments'] ?? 0);
                $totalAssignments += $assignmentCount;
                $groups[$key]['reports_covered'] += (int) ($group['reports_covered'] ?? 0);
                $groups[$key]['total_assignments'] += $assignmentCount;
                $groups[$key]['incident_ids'] = array_merge($groups[$key]['incident_ids'], array_values((array) ($group['incident_ids'] ?? [])));
                $groups[$key]['source_hubs'][] = $source['source_hub_name'];

                $statusCounts = (array) ($group['status_counts'] ?? []);
                if ($statusCounts === []) {
                    $statusCounts = ['assigned' => $assignmentCount];
                }

                foreach ($statusCounts as $status => $count) {
                    if (is_numeric($count)) {
                        $statusKey = (string) $status;
                        $groups[$key]['status_counts'][$statusKey] = ($groups[$key]['status_counts'][$statusKey] ?? 0) + (int) $count;
                    }
                }
            }

            foreach (($actions['timing_rows'] ?? []) as $row) {
                if (is_array($row)) {
                    $row['source_hub_id'] = $source['source_hub_id'];
                    $row['source_hub_name'] = $source['source_hub_name'];
                    $timingRows[] = $row;
                }
            }
        }

        return [
            'total_assignments' => $totalAssignments,
            'deployment_groups' => $this->sortedRows(array_values(array_map(function (array $row): array {
                $row['incident_ids'] = array_values(array_unique($row['incident_ids']));
                $row['source_hubs'] = array_values(array_unique($row['source_hubs']));

                return $row;
            }, $groups)), 'total_assignments'),
            'timing_rows' => $timingRows,
            'confidence_note' => 'Team deployment counts are summed from source SITREPs; source hub provenance is retained per group and timing row.',
        ];
    }

    private function mergeNeeds(array $normalized): array
    {
        $items = [];
        $categoryGroups = [];
        $total = 0;

        foreach ($normalized as $source) {
            $needs = $this->sourceSection($source, 'needs');
            foreach (($needs['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $resource = (string) ($item['resource'] ?? $item['name'] ?? 'Resource');
                $category = (string) ($item['category'] ?? 'Uncategorized');
                $key = strtolower($category.'|'.$resource);
                $quantity = (int) ($item['quantity_requested'] ?? $item['quantity'] ?? 0);
                $total += $quantity;

                $items[$key] ??= [
                    'resource' => $resource,
                    'category' => $category,
                    'quantity_requested' => 0,
                    'incident_count' => 0,
                    'sources' => [],
                    'source_hubs' => [],
                ];
                $items[$key]['quantity_requested'] += $quantity;
                $items[$key]['incident_count'] += (int) ($item['incident_count'] ?? 0);
                $items[$key]['source_hubs'][] = $source['source_hub_name'];
                $items[$key]['sources'][] = [
                    'source_hub_id' => $source['source_hub_id'],
                    'source_hub_name' => $source['source_hub_name'],
                    'quantity_requested' => $quantity,
                ];
            }

            foreach (($needs['category_groups'] ?? []) as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $category = $this->text($group['category'] ?? null, 'Uncategorized');
                $categoryGroups[$category] ??= [
                    'category' => $category,
                    'quantity_requested' => 0,
                    'resources' => [],
                    'source_hubs' => [],
                ];
                $categoryGroups[$category]['quantity_requested'] += (int) ($group['quantity_requested'] ?? 0);
                $categoryGroups[$category]['resources'] = array_merge($categoryGroups[$category]['resources'], array_values((array) ($group['resources'] ?? [])));
                $categoryGroups[$category]['source_hubs'][] = $source['source_hub_name'];
            }
        }

        ksort($items);
        ksort($categoryGroups);

        return [
            'total_quantity_requested' => $total,
            'category_groups' => array_values(array_map(function (array $group): array {
                $group['resources'] = array_values(array_unique(array_filter($group['resources'], 'is_scalar')));
                $group['source_hubs'] = array_values(array_unique($group['source_hubs']));
                $group['location_count'] = count($group['source_hubs']);

                return $group;
            }, $categoryGroups)),
            'items' => $this->sortedRows(array_values(array_map(function (array $item): array {
                $item['source_hubs'] = array_values(array_unique($item['source_hubs']));
                $item['location_count'] = count($item['source_hubs']);

                return $item;
            }, $items)), 'quantity_requested'),
            'confidence_note' => 'Resource demand is summed from source SITREP needs. Incident counts and source lists preserve provenance for drill-down.',
        ];
    }

    private function mergeDamage(array $normalized): array
    {
        $items = $this->mergeSectionItems($normalized, 'damage')['items'];
        $groups = [];

        foreach ($items as $item) {
            $label = $this->text($item['label'] ?? null, 'Reported damage');
            $key = strtolower($label);
            $groups[$key] ??= [
                'damage_type' => $label,
                'reports' => 0,
                'severity_signal' => '',
                'affected_assets' => [],
                'source_hubs' => [],
            ];
            $groups[$key]['reports']++;
            $groups[$key]['affected_assets'][] = $item['source']['asset_type'] ?? $item['source']['vehicle_type'] ?? $item['source']['structure_type'] ?? null;
            $groups[$key]['source_hubs'][] = $item['source_hub_name'] ?? $item['source_hub_id'] ?? null;
            if ($groups[$key]['severity_signal'] === '') {
                $groups[$key]['severity_signal'] = (string) ($item['source']['damage_level'] ?? $item['source']['severity'] ?? $item['value'] ?? '');
            }
        }

        return [
            'damage_groups' => $this->sortedRows(array_values(array_map(function (array $group): array {
                $group['affected_assets'] = implode(', ', array_values(array_unique(array_filter($group['affected_assets'], 'is_scalar'))));
                $group['source_hubs'] = array_values(array_unique(array_filter($group['source_hubs'], 'is_scalar')));

                return $group;
            }, $groups)), 'reports'),
            'items' => $items,
            'empty_state' => 'No damage entries available.',
            'confidence_note' => 'Damage rollup groups source-reported damage signals; individual source rows remain available in this payload.',
        ];
    }

    private function mergeGaps(array $normalized): array
    {
        $groups = [];

        foreach ($normalized as $source) {
            $gaps = $this->sourceSection($source, 'gaps');
            foreach (($gaps['items'] ?? []) as $gap) {
                if (! is_array($gap)) {
                    continue;
                }

                $title = $this->text($gap['title'] ?? $gap['label'] ?? null, 'Gap');
                $category = $this->text($gap['category'] ?? null, 'Reported Gap');
                $key = strtolower($category.'|'.$title);
                $groups[$key] ??= [
                    'category' => $category,
                    'title' => $title,
                    'body' => $gap['body'] ?? '',
                    'decision_relevance' => $gap['decision_relevance'] ?? $gap['body'] ?? '',
                    'evidence' => '',
                    'confidence_note' => $gap['confidence_note'] ?? '',
                    'source_hubs' => [],
                    'items' => [],
                ];
                $groups[$key]['source_hubs'][] = $source['source_hub_name'];

                if (($gap['evidence'] ?? '') !== '') {
                    $groups[$key]['evidence'] = trim($groups[$key]['evidence'].' '.$source['source_hub_name'].': '.$gap['evidence']);
                }

                foreach (($gap['items'] ?? []) as $item) {
                    if (is_array($item)) {
                        $item['source_hub_id'] = $source['source_hub_id'];
                        $item['source_hub_name'] = $source['source_hub_name'];
                        $groups[$key]['items'][] = $item;
                    }
                }
            }
        }

        return [
            'title' => 'Response Constraints and Confidence Gaps',
            'intro' => sprintf('This consolidated gap rollup groups repeated constraints across %d source hub%s while preserving route and source evidence.', count($normalized), count($normalized) === 1 ? '' : 's'),
            'items' => $this->sortedRows(array_values(array_map(function (array $group): array {
                $group['source_hubs'] = array_values(array_unique($group['source_hubs']));
                if ($group['evidence'] === '') {
                    $group['evidence'] = sprintf('Reported by %d source hub%s.', count($group['source_hubs']), count($group['source_hubs']) === 1 ? '' : 's');
                }

                return $group;
            }, $groups)), 'title', false),
            'empty_state' => 'No gaps identified.',
        ];
    }

    private function mergeSectionItems(array $normalized, string $section): array
    {
        $items = [];

        foreach ($normalized as $source) {
            $sectionData = $this->sourceSection($source, $section);
            foreach (($sectionData['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $item['source_hub_id'] = $source['source_hub_id'];
                    $item['source_hub_name'] = $source['source_hub_name'];
                    $items[] = $item;
                }
            }
        }

        return [
            'items' => $items,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     * @return array<int, array{id: mixed, lat: float|int|string, lng: float|int|string, source_hub_id: string}>
     */
    private function mergeIncidentCoordinates(array $normalized): array
    {
        $coordinates = [];
        $seen = [];

        foreach ($normalized as $source) {
            $sourceSnapshot = $this->sourceSection($source, 'source_snapshot');
            $items = $sourceSnapshot['incident_coordinates'] ?? [];
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item) || ! array_key_exists('id', $item) || ! array_key_exists('lat', $item) || ! array_key_exists('lng', $item)) {
                    continue;
                }

                $key = $source['source_hub_id'].'|'.$item['id'];
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $coordinates[] = [
                    'id' => $item['id'],
                    'lat' => $item['lat'],
                    'lng' => $item['lng'],
                    'source_hub_id' => (string) $source['source_hub_id'],
                ];
            }
        }

        return $coordinates;
    }

    /**
     * @param array<string, array<string, mixed>> $merged
     */
    private function mergeCardRows(array &$merged, mixed $rows, array $source): void
    {
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = $this->text($row['label'] ?? $row['title'] ?? null, 'Card');
            $key = strtolower($label);
            $merged[$key] ??= [
                'label' => $label,
                'value' => '',
                'note' => '',
                'source_values' => [],
                'source_notes' => [],
                'source_hubs' => [],
            ];

            $value = trim((string) ($row['value'] ?? ''));
            $note = trim((string) ($row['note'] ?? $row['body'] ?? ''));
            $merged[$key]['source_hubs'][] = $source['source_hub_name'];

            if ($value !== '') {
                $merged[$key]['source_values'][] = [
                    'source_hub_id' => $source['source_hub_id'],
                    'source_hub_name' => $source['source_hub_name'],
                    'value' => $value,
                    'numeric_value' => $this->leadingNumber($value),
                    'label' => $this->sourceCardLabel($value),
                ];
            }

            if ($note !== '') {
                $merged[$key]['source_notes'][] = [
                    'source_hub_id' => $source['source_hub_id'],
                    'source_hub_name' => $source['source_hub_name'],
                    'note' => $note,
                ];
            }

            $merged[$key]['value'] = $this->cardSummaryValue($merged[$key]);
            $merged[$key]['note'] = $this->cardSummaryNote($merged[$key]);
            $merged[$key]['source_hubs'] = array_values(array_unique($merged[$key]['source_hubs']));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $normalized
     */
    private function summaryCardNumericTotal(array $normalized, string $label): int
    {
        $total = 0;

        foreach ($normalized as $source) {
            $summary = $this->sourceSection($source, 'summary');
            $cards = array_merge(
                is_array($summary['gap_cards'] ?? null) ? $summary['gap_cards'] : [],
                is_array($summary['accomplishment_cards'] ?? null) ? $summary['accomplishment_cards'] : [],
            );

            foreach ($cards as $card) {
                if (! is_array($card) || strcasecmp((string) ($card['label'] ?? $card['title'] ?? ''), $label) !== 0) {
                    continue;
                }

                $numericValue = $this->leadingNumber(trim((string) ($card['value'] ?? '')));
                if ($numericValue !== null) {
                    $total += (int) $numericValue;
                }
            }
        }

        return $total;
    }

    /**
     * @param array<string, array<string, mixed>> $merged
     */
    private function mergeDecisionRows(array &$merged, mixed $rows, array $source): void
    {
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = $this->text($row['title'] ?? $row['label'] ?? null, 'Decision point');
            $key = strtolower($title);
            $merged[$key] ??= [
                'title' => $title,
                'body' => '',
                'source_bodies' => [],
                'source_hubs' => [],
            ];

            $body = trim((string) ($row['body'] ?? $row['decision_relevance'] ?? $row['note'] ?? ''));
            $merged[$key]['source_hubs'][] = $source['source_hub_name'];

            if ($body !== '') {
                $merged[$key]['source_bodies'][] = [
                    'source_hub_id' => $source['source_hub_id'],
                    'source_hub_name' => $source['source_hub_name'],
                    'body' => $body,
                ];
            }

            $merged[$key]['source_hubs'] = array_values(array_unique($merged[$key]['source_hubs']));
            $merged[$key]['body'] = $this->decisionSummaryBody($merged[$key]);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $merged
     */
    private function mergeWatchItems(array &$merged, mixed $rows, array $source): void
    {
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $text = trim((string) (is_array($row) ? ($row['title'] ?? $row['label'] ?? $row['body'] ?? '') : $row));

            if ($text === '') {
                continue;
            }

            $key = strtolower($text);
            $merged[$key] ??= [
                'title' => $text,
                'source_hubs' => [],
            ];
            $merged[$key]['source_hubs'][] = $source['source_hub_name'];
            $merged[$key]['source_hubs'] = array_values(array_unique($merged[$key]['source_hubs']));
        }
    }

    /**
     * @param array<string, mixed> $card
     */
    private function cardSummaryValue(array $card): string
    {
        $sourceValues = array_values(array_filter($card['source_values'] ?? [], 'is_array'));

        if (strcasecmp((string) ($card['label'] ?? ''), 'Access to Help') === 0) {
            $constrained = array_values(array_filter(
                $sourceValues,
                static fn (array $row): bool => strcasecmp(trim((string) ($row['label'] ?? $row['value'] ?? '')), 'Clear') !== 0,
            ));

            if ($constrained === []) {
                return 'All clear';
            }

            return sprintf('%d area%s constrained', count($constrained), count($constrained) === 1 ? '' : 's');
        }

        $numericValues = array_values(array_filter(
            array_map(static fn (array $row): mixed => $row['numeric_value'] ?? null, $sourceValues),
            static fn (mixed $value): bool => is_int($value) || is_float($value),
        ));

        if ($numericValues !== []) {
            $total = array_sum($numericValues);
            $formattedTotal = (string) (fmod((float) $total, 1.0) === 0.0 ? (int) $total : $total);

            if (strcasecmp((string) ($card['label'] ?? ''), 'Response Progress') === 0) {
                return $formattedTotal.' open';
            }

            return $formattedTotal;
        }

        $values = array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['value'] ?? ''),
            $sourceValues,
        )));

        if ($values === []) {
            return sprintf('%d source hub%s', count($card['source_hubs'] ?? []), count($card['source_hubs'] ?? []) === 1 ? '' : 's');
        }

        if (count($values) === 1) {
            return $values[0];
        }

        return sprintf('%d source values', count($values));
    }

    private function leadingNumber(string $value): int|float|null
    {
        if (preg_match('/^\s*(-?\d+(?:\.\d+)?)/', $value, $matches) !== 1) {
            return null;
        }

        $number = (float) $matches[1];

        return fmod($number, 1.0) === 0.0 ? (int) $number : $number;
    }

    private function sourceCardLabel(string $value): string
    {
        $label = trim($value);

        if (strcasecmp($label, 'No current access constraint reported') === 0) {
            return 'Clear';
        }

        $label = preg_replace('/\bcompleted team assignments\b/i', 'assignments', $label) ?? $label;
        $label = preg_replace('/\bteam assignments\b/i', 'assignments', $label) ?? $label;
        $label = preg_replace('/\bresource units\b/i', 'units', $label) ?? $label;

        return $label;
    }

    /**
     * @return array{families: int, people: int}
     */
    private function peopleFamilyCounts(string $value): array
    {
        $counts = ['families' => 0, 'people' => 0];

        if (preg_match('/(\d+)\s+famil(?:y|ies)\b/i', $value, $matches) === 1) {
            $counts['families'] = (int) $matches[1];
        }

        if (preg_match('/(\d+)\s+(?:people|persons|person)\b/i', $value, $matches) === 1) {
            $counts['people'] = (int) $matches[1];
        }

        return $counts;
    }

    /**
     * @param array<int, string|null> $parts
     */
    private function joinParts(array $parts, string $separator = '; '): string
    {
        return implode($separator, array_values(array_filter(
            array_map(static fn (?string $part): string => trim((string) $part), $parts),
            static fn (string $part): bool => $part !== '',
        )));
    }

    /**
     * @param array<string, mixed> $card
     */
    private function cardSummaryNote(array $card): string
    {
        $hubs = $card['source_hubs'] ?? [];
        $prefix = sprintf('Summed from %d source hub%s.', count($hubs), count($hubs) === 1 ? '' : 's');
        $topValues = $this->topCardSourceValues($card);
        $notes = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['note'] ?? '')),
            array_filter($card['source_notes'] ?? [], 'is_array'),
        ))));

        return trim(implode(' ', array_filter([
            $prefix,
            $topValues !== '' ? 'Highest source signals: '.$topValues.'.' : null,
            implode(' ', array_slice($notes, 0, 2)),
        ])));
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function decisionSummaryBody(array $decision): string
    {
        $hubs = $decision['source_hubs'] ?? [];
        $bodies = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['body'] ?? '')),
            array_filter($decision['source_bodies'] ?? [], 'is_array'),
        ))));

        $prefix = sprintf('Raised by %d source hub%s.', count($hubs), count($hubs) === 1 ? '' : 's');

        return trim($prefix.' '.implode(' ', array_slice($bodies, 0, 3)));
    }

    /**
     * @param array<string, mixed> $card
     */
    private function topCardSourceValues(array $card): string
    {
        $values = array_values(array_filter($card['source_values'] ?? [], 'is_array'));

        usort($values, static fn (array $left, array $right): int => ((float) ($right['numeric_value'] ?? -INF)) <=> ((float) ($left['numeric_value'] ?? -INF)));

        $parts = [];
        foreach (array_slice($values, 0, 3) as $row) {
            $label = trim((string) ($row['label'] ?? $row['value'] ?? ''));
            $hub = trim((string) ($row['source_hub_name'] ?? 'Source'));

            if ($label !== '') {
                $parts[] = $hub.' - '.$label;
            }
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $locationRows
     */
    private function areaPhrase(array $locationRows): string
    {
        $areas = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['area'] ?? '')),
            array_slice($locationRows, 0, 5),
        )));

        return $areas === [] ? 'the source coverage area' : implode(', ', $areas);
    }

    /**
     * @param array<int, array<string, mixed>> $incidentTypeRows
     */
    private function typePhrase(array $incidentTypeRows): string
    {
        $types = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['type'] ?? '')).((int) ($row['count'] ?? 0) > 0 ? ' ('.(int) $row['count'].')' : ''),
            array_slice($incidentTypeRows, 0, 5),
        )));

        return $types === [] ? 'unclassified incidents' : implode(', ', $types);
    }

    /**
     * @param array<string, int> $currentPicture
     * @param array<int, array<string, mixed>> $locationRows
     * @param array<int, array<string, mixed>> $incidentTypeRows
     * @param array<int, array<string, mixed>> $concernRows
     * @return array<int, array{title: string, body: string}>
     */
    private function consolidatedDecisionPoints(array $currentPicture, array $locationRows, array $incidentTypeRows, array $concernRows): array
    {
        $topConcern = $concernRows[0] ?? null;
        $topType = $incidentTypeRows[0]['type'] ?? 'current incidents';
        $topAreas = $this->areaPhrase($locationRows);
        $points = [
            [
                'title' => 'Life safety',
                'body' => sprintf(
                    '%d active and %d deferred reports remain open across %s, led by %s. Leadership should prioritize source hubs with concentrated open reports and verify whether cross-hub rescue, EMS, shelter, or protection support is needed.',
                    $currentPicture['active_reports'],
                    $currentPicture['deferred_reports'],
                    $topAreas,
                    $topType,
                ),
            ],
            [
                'title' => 'Resource posture',
                'body' => sprintf(
                    'Open source reports carry %d requested resource units and %d in-progress assignments. Leadership should compare city-level capacity against high-demand categories before committing support upstream or across barangays.',
                    $currentPicture['current_resource_units'],
                    $currentPicture['current_assignments'],
                ),
            ],
            [
                'title' => 'Source verification',
                'body' => 'Merged figures are planning signals from source SITREPs. Before public release or deployment decisions, verify source provenance, duplicated population counts, route conditions, and resource availability with contributing hubs.',
            ],
        ];

        if (is_array($topConcern) && ($topConcern['concern'] ?? '') !== '') {
            array_unshift($points, [
                'title' => 'Priority concern',
                'body' => sprintf(
                    '%s accounts for %d open reports across %s, with %d assignments and %d requested resource units tied to that concern group.',
                    (string) $topConcern['concern'],
                    (int) ($topConcern['open_reports'] ?? 0),
                    implode(', ', array_slice((array) ($topConcern['areas'] ?? []), 0, 5)),
                    (int) ($topConcern['current_assignments'] ?? 0),
                    (int) ($topConcern['resource_units'] ?? 0),
                ),
            ]);
        }

        return $points;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortedRows(array $rows, string $field, bool $numeric = true): array
    {
        usort($rows, static function (array $left, array $right) use ($field, $numeric): int {
            if ($numeric) {
                return ((int) ($right[$field] ?? 0)) <=> ((int) ($left[$field] ?? 0));
            }

            return strcmp((string) ($left[$field] ?? ''), (string) ($right[$field] ?? ''));
        });

        return $rows;
    }

    private function text(mixed $value, string $fallback): string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : $fallback;
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
