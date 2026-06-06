<?php

namespace Pbb\Sitreps\Viewer;

final class SitrepVisualizationDataBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(SitrepPayload $sitrep): array
    {
        $sections = [
            'summary' => $this->summary($sitrep),
            'situation' => $this->situation($sitrep),
            'population' => $this->population($sitrep),
            'actions' => $this->actions($sitrep),
            'needs' => $this->needs($sitrep),
            'gaps' => $this->gaps($sitrep),
            'map' => $this->map($sitrep),
        ];

        return [
            'schema_version' => 1,
            'renderer' => 'pbb-sitrep-viewer',
            'helper_targets' => [
                'ui.stat.cards',
                'ui.charts',
                'ui.map.legend',
                'ui.map.markers',
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function section(SitrepPayload $sitrep, string $section): array
    {
        $sections = $this->build($sitrep)['sections'];
        $section = strtolower(trim(str_replace('-', '_', $section)));

        if (! isset($sections[$section])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown SITREP visualization section [%s]. Supported sections: %s.',
                $section,
                implode(', ', array_keys($sections)),
            ));
        }

        return $sections[$section];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SitrepPayload $sitrep): array
    {
        $summary = $sitrep->section('summary');
        $population = $sitrep->section('population');
        $situation = $sitrep->section('situation');
        $current = is_array($situation['current_operating_picture'] ?? null) ? $situation['current_operating_picture'] : [];

        return [
            'stat_cards' => $this->dataset('ui.stat.cards', 'Key SITREP Metrics', [
                'items' => array_values(array_filter([
                    $this->statCard('people-at-risk', 'People at Risk', $this->number($population['people_at_risk'] ?? $population['numeric_total'] ?? 0), 'population.people-at-risk', 'warning'),
                    $this->statCard('people-helped', 'People Helped', $this->number($population['citizens_assisted'] ?? $population['callers_assisted'] ?? 0), 'population.people-helped', 'success'),
                    $this->statCard('open-reports', 'Open Reports', $this->number($current['open_reports'] ?? $summary['current_operating_picture']['open_reports'] ?? 0), 'sitrep.situation', 'info'),
                    $this->statCard('resource-units', 'Requested Units', $this->number($current['current_resource_units'] ?? 0), 'resource.requested', 'neutral'),
                ], static fn (array $item): bool => (float) ($item['value'] ?? 0) > 0)),
            ]),
            'gap_cards' => $this->summaryCards('Gaps', $summary['gap_cards'] ?? [], 'warning'),
            'accomplishment_cards' => $this->summaryCards('Accomplishments', $summary['accomplishment_cards'] ?? [], 'success'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function situation(SitrepPayload $sitrep): array
    {
        $situation = $sitrep->section('situation');

        return [
            'locations' => $this->dataset('ui.charts', 'Current Locations', [
                'type' => 'horizontal-bar',
                'value_label' => 'Incidents',
                'data' => array_map(fn (array $row): array => [
                    'id' => $this->text($row['area'] ?? $row['location'] ?? 'Location'),
                    'label' => $this->text($row['area'] ?? $row['location'] ?? 'Location'),
                    'value' => $this->number($row['count'] ?? $row['report_count'] ?? 0),
                    'secondary_label' => $this->text($row['alert_level'] ?? ''),
                    'tone' => $this->alertTone($row['alert_level'] ?? null),
                    'icon' => 'map.boundary',
                ], $this->rows($situation['locations'] ?? [])),
            ]),
            'incident_types' => $this->dataset('ui.charts', 'Current Incident Types', [
                'type' => 'horizontal-bar',
                'value_label' => 'Mentions',
                'secondary_value_label' => 'Locations',
                'data' => array_map(fn (array $row): array => [
                    'id' => $this->text($row['type'] ?? 'Incident type'),
                    'label' => $this->text($row['type'] ?? 'Incident type'),
                    'value' => $this->number($row['count'] ?? $row['mentions'] ?? 0),
                    'secondary_value' => $this->number($row['location_count'] ?? 0),
                    'secondary_label' => $this->plural($this->number($row['location_count'] ?? 0), 'location'),
                    'icon' => $this->incidentIcon($row['type'] ?? ''),
                ], $this->rows($situation['incident_types'] ?? [])),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function population(SitrepPayload $sitrep): array
    {
        $population = $sitrep->section('population');
        $groups = $this->rows($population['population_groups'] ?? []);
        $breakdowns = [];
        foreach ($groups as $group) {
            foreach ($this->rows($group['breakdowns'] ?? []) as $breakdown) {
                $breakdowns[] = [
                    'id' => strtolower($this->text($breakdown['breakdown'] ?? 'breakdown')),
                    'label' => $this->text($breakdown['breakdown'] ?? 'Breakdown'),
                    'value' => $this->number($breakdown['count'] ?? 0),
                    'secondary_value' => $this->number($breakdown['location_count'] ?? 0),
                    'secondary_label' => $this->plural($this->number($breakdown['location_count'] ?? 0), 'location'),
                    'icon' => $this->populationIcon($breakdown['breakdown'] ?? ''),
                ];
            }
        }

        return [
            'stat_cards' => $this->dataset('ui.stat.cards', 'Affected People', [
                'items' => [
                    $this->statCard('people-at-risk', 'People at Risk', $this->number($population['people_at_risk'] ?? $population['numeric_total'] ?? 0), 'population.people-at-risk', 'warning'),
                    $this->statCard('people-helped', 'People Helped', $this->number($population['citizens_assisted'] ?? $population['callers_assisted'] ?? 0), 'population.people-helped', 'success'),
                    $this->statCard('current-records', 'Current Records', $this->number($population['record_count'] ?? count($population['items'] ?? [])), 'assets.clipboard', 'info'),
                ],
            ]),
            'population_groups' => $this->dataset('ui.charts', 'Population Signals', [
                'type' => 'horizontal-bar',
                'value_label' => 'People / Families',
                'data' => array_map(fn (array $row): array => [
                    'id' => $this->text($row['population_signal'] ?? 'Population signal'),
                    'label' => $this->text($row['population_signal'] ?? 'Population signal'),
                    'value' => $this->number($row['numeric_total'] ?? $row['reports'] ?? 0),
                    'secondary_value' => $this->number($row['location_count'] ?? 0),
                    'secondary_label' => $this->text($row['people_or_families'] ?? $row['people_families'] ?? ''),
                    'icon' => $this->populationIcon($row['population_signal'] ?? ''),
                ], $groups),
            ]),
            'member_breakdown' => $this->dataset('ui.charts', 'Declared Member Breakdown', [
                'type' => 'bar',
                'value_label' => 'People',
                'data' => $breakdowns,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actions(SitrepPayload $sitrep): array
    {
        $actions = $sitrep->section('actions');

        return [
            'assignment_status' => $this->dataset('ui.charts', 'Team Assignment Status', [
                'type' => 'stacked-bar',
                'data' => array_map(function (array $row): array {
                    $counts = is_array($row['status_counts'] ?? null) ? $row['status_counts'] : [];

                    return [
                        'id' => $this->text($row['team'] ?? 'Team'),
                        'label' => $this->text($row['team'] ?? 'Team'),
                        'secondary_label' => $this->text($row['category'] ?? ''),
                        'segments' => array_values(array_filter(array_map(
                            fn (string $status, mixed $value): array => [
                                'label' => $this->statusLabel($status),
                                'value' => $this->number($value),
                                'tone' => $this->statusTone($status),
                            ],
                            array_keys($counts),
                            array_values($counts),
                        ), static fn (array $segment): bool => (float) ($segment['value'] ?? 0) > 0)),
                    ];
                }, $this->rows($actions['deployment_groups'] ?? [])),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function needs(SitrepPayload $sitrep): array
    {
        $needs = $sitrep->section('needs');

        return [
            'category_demand' => $this->dataset('ui.charts', 'Resource Demand By Category', [
                'type' => 'horizontal-bar',
                'value_label' => 'Requested',
                'data' => array_map(fn (array $row): array => [
                    'id' => $this->text($row['category'] ?? 'Category'),
                    'label' => $this->text($row['category'] ?? 'Category'),
                    'value' => $this->number($row['quantity_requested'] ?? $row['quantity'] ?? 0),
                    'secondary_value' => $this->number($row['location_count'] ?? 0),
                    'secondary_label' => $this->plural($this->number($row['location_count'] ?? 0), 'location'),
                    'icon' => $this->resourceIcon($row['category'] ?? ''),
                ], $this->rows($needs['category_groups'] ?? [])),
            ]),
            'resource_needs' => $this->dataset('ui.charts', 'Top Resource Needs', [
                'type' => 'horizontal-bar',
                'value_label' => 'Requested',
                'data' => array_map(fn (array $row): array => [
                    'id' => $this->text($row['resource'] ?? 'Resource'),
                    'label' => $this->text($row['resource'] ?? 'Resource'),
                    'value' => $this->number($row['quantity_requested'] ?? $row['quantity'] ?? 0),
                    'secondary_value' => $this->number($row['incident_count'] ?? 0),
                    'secondary_label' => $this->plural($this->number($row['incident_count'] ?? 0), 'incident'),
                    'icon' => $this->resourceIcon($row['category'] ?? $row['resource'] ?? ''),
                ], $this->rows($needs['items'] ?? [])),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gaps(SitrepPayload $sitrep): array
    {
        $gaps = $sitrep->section('gaps');
        $items = $this->rows($gaps['items'] ?? []);

        return [
            'gap_types' => $this->dataset('ui.stat.cards', 'Response Constraints', [
                'items' => array_map(fn (array $row): array => [
                    'id' => strtolower(str_replace(' ', '-', $this->text($row['title'] ?? $row['label'] ?? 'gap'))),
                    'label' => $this->text($row['title'] ?? $row['label'] ?? 'Gap'),
                    'value' => $this->number($row['location_count'] ?? count($row['source_hubs'] ?? [])),
                    'unit' => 'locations',
                    'note' => $this->text($row['summary'] ?? $row['description'] ?? ''),
                    'icon' => 'sitrep.gaps',
                    'tone' => 'warning',
                ], $items),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function map(SitrepPayload $sitrep): array
    {
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $coordinates = $this->rows($sourceSnapshot['incident_coordinates'] ?? []);

        return [
            'incident_markers' => $this->dataset('ui.map.markers', 'Incident Coordinates', [
                'items' => array_values(array_filter(array_map(fn (array $row): ?array => $this->marker($row), $coordinates))),
            ]),
            'legend' => $this->dataset('ui.map.legend', 'SITREP Map Legend', [
                'sections' => [
                    [
                        'title' => 'Evidence',
                        'items' => [
                            ['id' => 'incident', 'label' => 'Incident', 'marker' => 'pin', 'icon' => 'places.pin', 'tone' => 'warning'],
                            ['id' => 'cluster', 'label' => 'Cluster', 'marker' => 'cluster', 'icon' => 'map.cluster', 'tone' => 'info'],
                            ['id' => 'source-hub', 'label' => 'Source hub', 'marker' => 'hub', 'icon' => 'map.source-hub', 'tone' => 'neutral'],
                        ],
                    ],
                ],
            ]),
        ];
    }

    /**
     * @param array<int, mixed> $cards
     * @return array<string, mixed>
     */
    private function summaryCards(string $title, array $cards, string $tone): array
    {
        return $this->dataset('ui.stat.cards', $title, [
            'items' => array_map(fn (array $card): array => [
                'id' => strtolower(str_replace(' ', '-', $this->text($card['label'] ?? $card['title'] ?? 'card'))),
                'label' => $this->text($card['label'] ?? $card['title'] ?? 'Card'),
                'value' => $this->number($card['numeric_value'] ?? $card['value'] ?? 0),
                'note' => $this->text($card['note'] ?? ''),
                'tone' => $tone,
            ], $this->rows($cards)),
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function dataset(string $component, string $title, array $extra): array
    {
        return ['component' => $component, 'title' => $title] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function statCard(string $id, string $label, int|float $value, string $icon, string $tone): array
    {
        return compact('id', 'label', 'value', 'icon', 'tone');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function marker(array $row): ?array
    {
        $lat = $row['lat'] ?? $row['latitude'] ?? null;
        $lng = $row['lng'] ?? $row['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return [
            'id' => (string) ($row['id'] ?? $row['incident_id'] ?? md5((string) $lat.'|'.(string) $lng)),
            'type' => 'incident',
            'lat' => round((float) $lat, 5),
            'lng' => round((float) $lng, 5),
            'label' => $this->text($row['label'] ?? 'Incident marker'),
            'tone' => 'warning',
            'icon' => 'places.pin',
        ];
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    private function text(mixed $value, string $default = ''): string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : $default;
    }

    private function number(mixed $value): int|float
    {
        if (is_numeric($value)) {
            $number = (float) $value;

            return floor($number) === $number ? (int) $number : $number;
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', (string) $value, $match) === 1) {
            $number = (float) $match[0];

            return floor($number) === $number ? (int) $number : $number;
        }

        return 0;
    }

    private function plural(int|float $count, string $singular): string
    {
        return sprintf('%s %s', $count, (float) $count === 1.0 ? $singular : $singular.'s');
    }

    private function alertTone(mixed $alert): string
    {
        return match (strtolower($this->text($alert))) {
            'critical' => 'critical',
            'elevated' => 'warning',
            'normal' => 'success',
            default => 'neutral',
        };
    }

    private function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'on_scene', 'completed' => 'success',
            'en_route', 'accepted' => 'info',
            'cancelled' => 'danger',
            default => 'neutral',
        };
    }

    private function incidentIcon(mixed $type): string
    {
        $value = strtolower($this->text($type));

        return match (true) {
            str_contains($value, 'flood') => 'hazard.flood',
            str_contains($value, 'fire') => 'hazard.fire',
            str_contains($value, 'medical') || str_contains($value, 'patient') => 'hazard.medical',
            str_contains($value, 'rescue') => 'hazard.rescue',
            str_contains($value, 'landslide') => 'hazard.landslide',
            str_contains($value, 'infrastructure') => 'hazard.infrastructure',
            str_contains($value, 'vehicle') => 'hazard.vehicle',
            str_contains($value, 'disturbance') || str_contains($value, 'riot') => 'hazard.public-safety',
            str_contains($value, 'missing') => 'hazard.missing-person',
            str_contains($value, 'evac') => 'hazard.evacuation',
            default => 'sitrep.situation',
        };
    }

    private function populationIcon(mixed $type): string
    {
        $value = strtolower($this->text($type));

        return match (true) {
            str_contains($value, 'help') => 'population.people-helped',
            str_contains($value, 'risk') => 'population.people-at-risk',
            str_contains($value, 'family') => 'population.family',
            str_contains($value, 'child') => 'population.children',
            str_contains($value, 'senior') => 'population.senior',
            str_contains($value, 'pwd') => 'population.pwd',
            str_contains($value, 'pregnant') => 'population.pregnant',
            str_contains($value, 'patient') || str_contains($value, 'injur') => 'population.patient',
            str_contains($value, 'displaced') => 'population.displaced',
            str_contains($value, 'shelter') || str_contains($value, 'evac') => 'population.shelter',
            default => 'sitrep.population',
        };
    }

    private function resourceIcon(mixed $type): string
    {
        $value = strtolower($this->text($type));

        return match (true) {
            str_contains($value, 'medical') => 'resource.medical-supplies',
            str_contains($value, 'food') || str_contains($value, 'water') => 'resource.food-water',
            str_contains($value, 'rescue') => 'resource.rescue-equipment',
            str_contains($value, 'heavy') || str_contains($value, 'clearing') => 'resource.heavy-equipment',
            str_contains($value, 'sanitation') => 'resource.sanitation',
            str_contains($value, 'shelter') => 'resource.shelter-supplies',
            str_contains($value, 'transport') => 'resource.transport',
            default => 'resource.requested',
        };
    }
}
