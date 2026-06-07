<?php

namespace Tests\Unit;

use Pbb\Sitreps\Consolidation\SitrepConsolidator;
use Pbb\Sitreps\Consolidation\SitrepNormalizer;
use Pbb\Sitreps\Consolidation\Staging\FilesystemSitrepStagingStore;
use Pbb\Sitreps\Consolidation\Staging\InMemorySitrepStagingStore;
use PHPUnit\Framework\TestCase;

class SitrepConsolidatorSdkTest extends TestCase
{
    public function test_groups_mixed_sitreps_by_source_deployment(): void
    {
        $consolidator = new SitrepConsolidator();

        $grouped = $consolidator->groupByDeployment([
            $this->sitrep(12, 'barangay', 'Normal'),
            $this->sitrep(13, 'barangay', 'Elevated'),
            $this->sitrep(21, 'city', 'Critical'),
        ]);

        $this->assertSame([], $grouped['issues']);
        $this->assertCount(2, $grouped['groups']['barangay']);
        $this->assertCount(1, $grouped['groups']['city']);
    }

    public function test_staging_overwrites_latest_sitrep_by_deployment_and_hub_id(): void
    {
        $normalizer = new SitrepNormalizer();
        $staging = new InMemorySitrepStagingStore();

        $first = $normalizer->normalize($this->sitrep(12, 'barangay', 'Normal', sequence: 1))['normalized'];
        $second = $normalizer->normalize($this->sitrep(12, 'barangay', 'Critical', sequence: 2))['normalized'];

        $staging->stage($first);
        $staging->stage($second);

        $items = $staging->list('barangay');

        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['sequence_number']);
        $this->assertSame('Critical', $items[0]['alert_level']);
    }

    public function test_filesystem_staging_uses_hub_id_json_filename(): void
    {
        $normalizer = new SitrepNormalizer();
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pbb-sitrep-consolidator-test-'.bin2hex(random_bytes(6));
        $staging = new FilesystemSitrepStagingStore($root);

        try {
            $normalized = $normalizer->normalize($this->sitrep(72_217_029, 'barangay', 'Elevated'))['normalized'];
            $receipt = $staging->stage($normalized);

            $this->assertSame('barangay/72217029.json', $receipt['key']);
            $this->assertFileExists($root.DIRECTORY_SEPARATOR.'barangay'.DIRECTORY_SEPARATOR.'72217029.json');
            $listed = $staging->list('barangay');

            $this->assertCount(1, $listed);
            $this->assertSame('72217029', $listed[0]['source_hub_id']);
            $this->assertArrayHasKey('payload', $listed[0]);

            $staging->forget('barangay', '72217029');

            $this->assertSame([], $staging->list('barangay'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_filesystem_staging_rejects_unsafe_path_segments(): void
    {
        $normalizer = new SitrepNormalizer();
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pbb-sitrep-consolidator-test-'.bin2hex(random_bytes(6));
        $staging = new FilesystemSitrepStagingStore($root);
        $normalized = $normalizer->normalize($this->sitrep(12, 'barangay'))['normalized'];
        $normalized['source_hub_id'] = '../outside';

        try {
            $this->expectException(\InvalidArgumentException::class);
            $staging->stage($normalized);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_consolidates_three_barangay_sitreps_into_city_sitrep(): void
    {
        $consolidator = new SitrepConsolidator();

        $result = $consolidator->consolidate([
            $this->sitrep(12, 'barangay', 'Normal', totalIncidents: 4, resourceUnits: 10, population: 20),
            $this->sitrep(13, 'barangay', 'Critical', totalIncidents: 7, resourceUnits: 15, population: 30),
            $this->sitrep(14, 'barangay', 'Elevated', totalIncidents: 3, resourceUnits: 5, population: 10),
        ], [
            'target_level' => 'city',
            'target_hub_id' => '21',
            'target_hub_name' => 'Cebu City, Cebu',
            'coverage_area' => 'Cebu City, Cebu',
            'period_started_at' => '2026-05-29T17:00:00+08:00',
            'period_ended_at' => '2026-05-29T17:15:00+08:00',
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame('Critical', $result->sitrep['alert_level']);
        $this->assertSame(2, $result->sitrep['schema_version']);
        $this->assertSame(3, $result->sitrep['location_count']);
        $this->assertSame(14, $result->sitrep['summary']['rollup']['supporting_metrics']['total_incidents']);
        $this->assertSame(30, $result->sitrep['summary']['rollup']['supporting_metrics']['resource_need_units']);
        $this->assertCount(3, $result->sitrep['summary']['items']);
        $this->assertSame(60, $result->sitrep['population']['rollup']['numeric_total']);
        $this->assertSame('consolidated', $result->sitrep['source_snapshot']['rollup']['generation']['type']);
        $this->assertSame('System Generated', $result->sitrep['source_snapshot']['rollup']['generation']['prepared_by_label']);
        $this->assertSame('21', $result->sitrep['source_snapshot']['rollup']['hub_node']['snapshot']['hub_id']);
        $this->assertSame('Cebu City, Cebu', $result->sitrep['source_snapshot']['rollup']['hub_node']['snapshot']['name']);
        $this->assertSame('city', $result->sitrep['source_snapshot']['rollup']['hub_node']['snapshot']['deployment']);
        $this->assertCount(3, $result->sitrep['source_snapshot']['rollup']['hub_nodes']);
        $this->assertSame(12, $result->sitrep['source_snapshot']['rollup']['hub_nodes'][0]['snapshot']['hub_id']);
        $this->assertSame('draft', $result->sitrep['status']);
        $this->assertSame('private', $result->sitrep['visibility']);
        $this->assertSame('2026-05-29T17:00:00+08:00', $result->sitrep['period_started_at']);
        $this->assertSame('2026-05-29T17:15:00+08:00', $result->sitrep['period_ended_at']);
        $this->assertSame('Consolidator preserves source totals for planning awareness; validation remains app-owned.', $result->sitrep['population']['rollup']['confidence_note']);
        $this->assertSame('Source SITREPs', $result->sitrep['actions']['rollup']['deployment_groups'][0]['category']);
        $this->assertSame('Consolidated Sources', $result->sitrep['actions']['rollup']['deployment_groups'][0]['team']);
        $this->assertSame(3, $result->sitrep['actions']['rollup']['deployment_groups'][0]['status_counts']['assigned']);
        $this->assertCount(3, $result->sitrep['source_snapshot']['rollup']['source_sitreps']);
        $this->assertCount(3, $result->sitrep['source_snapshot']['items']);
        $this->assertSame(['Normal', 'Critical', 'Elevated'], array_column($result->sitrep['source_snapshot']['items'], 'alert_level'));
        $this->assertSame(['Normal', 'Critical', 'Elevated'], array_column(array_column($result->sitrep['source_snapshot']['items'], 'location'), 'alert_level'));
        $this->assertSame(['Normal', 'Critical', 'Elevated'], array_column($result->sitrep['source_snapshot']['rollup']['source_sitreps'], 'alert_level'));
    }

    public function test_single_source_v2_consolidation_preserves_rich_source_sections(): void
    {
        $consolidator = new SitrepConsolidator();
        $source = $this->sitrep(12, 'barangay', 'Elevated', totalIncidents: 4, resourceUnits: 10, population: 20);
        $source['schema_version'] = 2;
        $source['location_count'] = 1;
        $location = [
            'id' => '12',
            'name' => 'Hub 12',
            'deployment' => 'barangay',
            'relay_hub_id' => null,
        ];
        $source['summary']['headline'] = 'Rich source headline';
        $source['summary']['gap_cards'] = [
            ['label' => 'People at Risk', 'value' => '20 people', 'note' => 'Life-safety source signal.'],
        ];
        $source['summary']['accomplishment_cards'] = [
            ['label' => 'People Helped', 'value' => '8 people helped', 'note' => 'Source accomplishment.'],
        ];
        $source['situation'] = [
            'executive_assessment' => 'Source executive assessment retained.',
            'locations' => [
                ['area' => 'Guadalupe', 'count' => 4],
            ],
        ];
        foreach (['summary', 'situation', 'damage', 'population', 'actions', 'needs', 'gaps', 'data_quality'] as $section) {
            $source[$section] = [
                'rollup' => $source[$section],
                'items' => [[
                    'location' => $location,
                    'data' => $source[$section],
                ]],
            ];
        }

        $result = $consolidator->consolidate([$source], [
            'target_level' => 'city',
            'target_hub_id' => '21',
            'target_hub_name' => 'Cebu City, Cebu',
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->sitrep['schema_version']);
        $this->assertSame(1, $result->sitrep['location_count']);
        $this->assertSame('Rich source headline', $result->sitrep['summary']['rollup']['headline']);
        $this->assertSame('People at Risk', $result->sitrep['summary']['rollup']['gap_cards'][0]['label']);
        $this->assertSame('People Helped', $result->sitrep['summary']['rollup']['accomplishment_cards'][0]['label']);
        $this->assertSame('Source executive assessment retained.', $result->sitrep['situation']['rollup']['executive_assessment']);
        $this->assertSame('Guadalupe', $result->sitrep['situation']['rollup']['locations'][0]['area']);
        $this->assertSame(4, $result->sitrep['situation']['rollup']['locations'][0]['count']);
        $this->assertSame('Hub 12', $result->sitrep['summary']['items'][0]['location']['name']);
    }

    public function test_consolidated_sitrep_uses_source_period_bounds_and_rolls_up_incident_coordinates(): void
    {
        $consolidator = new SitrepConsolidator();

        $first = $this->sitrep(12, 'barangay', sequence: 1);
        $first['period_started_at'] = '2026-05-29T16:45:00+08:00';
        $first['period_ended_at'] = '2026-05-29T17:00:00+08:00';
        $first['source_snapshot']['incident_coordinates'] = [
            ['id' => 101, 'lat' => 10.33049, 'lng' => 123.88257],
        ];

        $second = $this->sitrep(13, 'barangay', sequence: 2);
        $second['period_started_at'] = '2026-05-29T17:00:00+08:00';
        $second['period_ended_at'] = '2026-05-29T17:30:00+08:00';
        $second['source_snapshot']['incident_coordinates'] = [
            ['id' => 101, 'lat' => 10.33111, 'lng' => 123.88333],
        ];

        $result = $consolidator->consolidate([$first, $second], [
            'target_level' => 'city',
            'target_hub_id' => '21',
            'target_hub_name' => 'Cebu City, Cebu',
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame('2026-05-29T16:45:00+08:00', $result->sitrep['period_started_at']);
        $this->assertSame('2026-05-29T17:30:00+08:00', $result->sitrep['period_ended_at']);
        $this->assertSame([
            ['id' => 101, 'lat' => 10.33049, 'lng' => 123.88257, 'source_hub_id' => '12'],
            ['id' => 101, 'lat' => 10.33111, 'lng' => 123.88333, 'source_hub_id' => '13'],
        ], $result->sitrep['source_snapshot']['rollup']['incident_coordinates']);
    }

    public function test_consolidated_period_bounds_compare_instants_across_timezone_offsets(): void
    {
        $consolidator = new SitrepConsolidator();

        $earlierInstant = $this->sitrep(12, 'barangay', sequence: 1);
        $earlierInstant['period_started_at'] = '2026-05-29T00:30:00+08:00';
        $earlierInstant['period_ended_at'] = '2026-05-29T00:45:00+08:00';

        $lexicallyEarlier = $this->sitrep(13, 'barangay', sequence: 2);
        $lexicallyEarlier['period_started_at'] = '2026-05-28T23:00:00+00:00';
        $lexicallyEarlier['period_ended_at'] = '2026-05-29T01:00:00+00:00';

        $result = $consolidator->consolidate([$earlierInstant, $lexicallyEarlier], [
            'target_level' => 'city',
            'target_hub_id' => '21',
            'target_hub_name' => 'Cebu City, Cebu',
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame('2026-05-29T00:30:00+08:00', $result->sitrep['period_started_at']);
        $this->assertSame('2026-05-29T01:00:00+00:00', $result->sitrep['period_ended_at']);
    }

    public function test_consolidated_rollups_preserve_source_operational_fidelity(): void
    {
        $consolidator = new SitrepConsolidator();

        $first = $this->richSitrep(12, 'Guadalupe', 'Flood', 'Team Alpha', 'Rescue Boat', 'Road/access constraints may affect movement');
        $second = $this->richSitrep(13, 'Apas', 'Road Accident', 'Team Bravo', 'Ambulance', 'Resource supply not confirmed');

        $result = $consolidator->consolidate([$first, $second], [
            'target_level' => 'city',
            'target_hub_id' => '11',
            'target_hub_name' => 'CEBU CITY, CEBU',
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->sitrep['location_count']);
        $this->assertSame('People at Risk', $result->sitrep['summary']['rollup']['gap_cards'][0]['label']);
        $this->assertSame('2', $result->sitrep['summary']['rollup']['gap_cards'][0]['value']);
        $this->assertCount(2, $result->sitrep['summary']['rollup']['gap_cards'][0]['source_values']);
        $this->assertSame(1, $result->sitrep['summary']['rollup']['gap_cards'][0]['source_values'][0]['numeric_value']);
        $this->assertSame('1 active report', $result->sitrep['summary']['rollup']['gap_cards'][0]['source_values'][0]['label']);
        $this->assertSame('Access to Help', $result->sitrep['summary']['rollup']['gap_cards'][1]['label']);
        $this->assertSame('All clear', $result->sitrep['summary']['rollup']['gap_cards'][1]['value']);
        $this->assertSame('Clear', $result->sitrep['summary']['rollup']['gap_cards'][1]['source_values'][0]['label']);
        $this->assertSame('Response Progress', $result->sitrep['summary']['rollup']['gap_cards'][2]['label']);
        $this->assertSame('2 open', $result->sitrep['summary']['rollup']['gap_cards'][2]['value']);
        $this->assertSame('People Helped', $result->sitrep['summary']['rollup']['accomplishment_cards'][0]['label']);
        $this->assertSame('2', $result->sitrep['summary']['rollup']['accomplishment_cards'][0]['value']);
        $this->assertSame('Teams / Resources Deployed', $result->sitrep['summary']['rollup']['accomplishment_cards'][1]['label']);
        $this->assertSame('1 assignments; 2 units', $result->sitrep['summary']['rollup']['accomplishment_cards'][1]['source_values'][0]['label']);
        $this->assertSame('River level monitoring', $result->sitrep['summary']['rollup']['priority_watch_items'][0]['title']);
        $this->assertSame('Life safety', $result->sitrep['summary']['rollup']['decision_points'][0]['title']);
        $this->assertStringContainsString('Raised by 2 source hubs', $result->sitrep['summary']['rollup']['decision_points'][0]['body']);
        $this->assertSame(2, $result->sitrep['situation']['rollup']['current_operating_picture']['open_reports']);
        $this->assertSame('Guadalupe', $result->sitrep['situation']['rollup']['locations'][0]['area']);
        $this->assertSame('Elevated', $result->sitrep['situation']['rollup']['locations'][0]['alert_level']);
        $this->assertSame('Flood', $result->sitrep['situation']['rollup']['incident_types'][0]['type']);
        $this->assertSame(1, $result->sitrep['situation']['rollup']['incident_types'][0]['location_count']);
        $this->assertSame('Life safety', $result->sitrep['situation']['rollup']['concern_groups'][0]['concern']);
        $this->assertSame('Barangay Teams', $result->sitrep['actions']['rollup']['deployment_groups'][0]['category']);
        $this->assertSame(1, $result->sitrep['actions']['rollup']['deployment_groups'][0]['status_counts']['assigned']);
        $this->assertSame(1, $result->sitrep['actions']['rollup']['deployment_groups'][1]['status_counts']['en_route']);
        $this->assertCount(2, $result->sitrep['actions']['rollup']['timing_rows']);
        $this->assertSame('2h', $result->sitrep['actions']['rollup']['timing_rows'][0]['elapsed_time']);
        $this->assertSame('1h', $result->sitrep['actions']['rollup']['timing_rows'][1]['elapsed_time']);
        $this->assertSame('Transport', $result->sitrep['needs']['rollup']['category_groups'][0]['category']);
        $this->assertSame(2, $result->sitrep['needs']['rollup']['category_groups'][0]['location_count']);
        $this->assertSame(5, $result->sitrep['needs']['rollup']['category_groups'][0]['quantity_requested']);
        $this->assertSame(1, $result->sitrep['needs']['rollup']['items'][0]['location_count']);
        $this->assertSame(5, array_sum(array_column($result->sitrep['needs']['rollup']['items'], 'quantity_requested')));
        $this->assertSame('People injured', $result->sitrep['population']['rollup']['population_groups'][0]['population_signal']);
        $this->assertSame(2, $result->sitrep['population']['rollup']['people_at_risk']);
        $this->assertSame(2, $result->sitrep['population']['rollup']['citizens_assisted']);
        $this->assertSame(2, $result->sitrep['population']['rollup']['population_groups'][0]['reports']);
        $this->assertSame('2 people', $result->sitrep['population']['rollup']['population_groups'][0]['people_or_families']);
        $affectedFamily = collect($result->sitrep['population']['rollup']['population_groups'])
            ->firstWhere('population_signal', 'Affected family');
        $this->assertSame(2, $affectedFamily['reports']);
        $this->assertSame(2, $affectedFamily['location_count']);
        $this->assertSame('3 families / 15 people', $affectedFamily['people_or_families']);
        $this->assertSame([
            ['breakdown' => 'Children', 'count' => 7, 'location_count' => 2],
            ['breakdown' => 'Senior citizens', 'count' => 3, 'location_count' => 2],
            ['breakdown' => 'PWD', 'count' => 3, 'location_count' => 2],
            ['breakdown' => 'Pregnant', 'count' => 2, 'location_count' => 2],
        ], array_map(
            static fn (array $row): array => [
                'breakdown' => $row['breakdown'],
                'count' => $row['count'],
                'location_count' => $row['location_count'],
            ],
            $affectedFamily['breakdowns'],
        ));
        $this->assertSame('Infrastructure damage', $result->sitrep['damage']['rollup']['damage_groups'][0]['damage_type']);
        $this->assertSame(2, $result->sitrep['damage']['rollup']['damage_groups'][0]['reports']);
        $this->assertCount(2, $result->sitrep['gaps']['rollup']['items']);
        $this->assertSame('Movement', $result->sitrep['gaps']['rollup']['items'][0]['category']);
    }

    public function test_rejects_mixed_source_deployment_consolidation(): void
    {
        $consolidator = new SitrepConsolidator();

        $result = $consolidator->consolidate([
            $this->sitrep(12, 'barangay'),
            $this->sitrep(21, 'city'),
        ], []);

        $this->assertFalse($result->ok);
        $this->assertSame('mixed_source_deployment', $result->errors()[0]->code);
    }

    public function test_rejects_duplicate_source_hub_reports(): void
    {
        $consolidator = new SitrepConsolidator();

        $result = $consolidator->consolidate([
            $this->sitrep(12, 'barangay', sequence: 1, totalIncidents: 4),
            $this->sitrep(12, 'barangay', sequence: 2, totalIncidents: 7),
        ], []);

        $this->assertFalse($result->ok);
        $this->assertSame('duplicate_source_hub', $result->errors()[0]->code);
        $this->assertSame(['12'], $result->errors()[0]->value);
    }

    public function test_consolidates_normalized_records_from_filesystem_staging(): void
    {
        $normalizer = new SitrepNormalizer();
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pbb-sitrep-consolidator-test-'.bin2hex(random_bytes(6));
        $staging = new FilesystemSitrepStagingStore($root);
        $consolidator = new SitrepConsolidator();

        try {
            $staging->stage($normalizer->normalize($this->sitrep(12, 'barangay', totalIncidents: 4))['normalized']);
            $staging->stage($normalizer->normalize($this->sitrep(13, 'barangay', totalIncidents: 7))['normalized']);

            $result = $consolidator->consolidate($staging->list('barangay'), []);

            $this->assertTrue($result->ok);
            $this->assertSame(11, $result->sitrep['summary']['rollup']['supporting_metrics']['total_incidents']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_rejects_missing_deployment_and_hub_id(): void
    {
        $consolidator = new SitrepConsolidator();
        $sitrep = $this->sitrep(12, 'barangay');
        unset($sitrep['source_snapshot']['hub_node']['snapshot']['deployment'], $sitrep['source_snapshot']['hub_node']['snapshot']['hub_id']);

        $result = $consolidator->consolidate([$sitrep], []);

        $this->assertFalse($result->ok);
        $this->assertSame(['missing_source_deployment', 'missing_source_hub_id'], array_map(
            static fn ($issue) => $issue->code,
            $result->errors(),
        ));
    }

    private function sitrep(
        int $hubId,
        string $deployment,
        string $alertLevel = 'Normal',
        int $sequence = 1,
        int $totalIncidents = 1,
        int $resourceUnits = 1,
        int $population = 1,
    ): array {
        return [
            'id' => $sequence,
            'sequence_number' => $sequence,
            'title' => sprintf('%s SITREP', ucfirst($deployment)),
            'coverage_area' => 'Sample Coverage',
            'period_started_at' => '2026-05-29T17:00:00+08:00',
            'period_ended_at' => '2026-05-29T17:15:00+08:00',
            'generated_at' => '2026-05-29T17:16:00+08:00',
            'alert_level' => $alertLevel,
            'summary' => [
                'supporting_metrics' => [
                    'total_incidents' => $totalIncidents,
                    'resource_need_units' => $resourceUnits,
                ],
                'status_counts' => [
                    'Active' => $totalIncidents,
                ],
            ],
            'situation' => [],
            'damage' => ['items' => []],
            'population' => [
                'numeric_total' => $population,
                'record_count' => 1,
            ],
            'actions' => [
                'deployment_groups' => [
                    ['total_assignments' => 1],
                ],
            ],
            'needs' => [
                'items' => [
                    [
                        'resource' => 'Rescue Boat',
                        'category' => 'Transport',
                        'quantity_requested' => $resourceUnits,
                    ],
                ],
            ],
            'gaps' => ['items' => []],
            'source_snapshot' => [
                'hub_node' => [
                    'snapshot' => [
                        'hub_id' => $hubId,
                        'name' => sprintf('Hub %d', $hubId),
                        'deployment' => $deployment,
                    ],
                ],
            ],
            'privacy_redactions' => [],
            'data_quality' => [],
        ];
    }

    private function richSitrep(int $hubId, string $area, string $type, string $team, string $resource, string $gapTitle): array
    {
        $sitrep = $this->sitrep($hubId, 'barangay', 'Elevated', sequence: $hubId, totalIncidents: 1, resourceUnits: 2, population: 3);
        $location = [
            'id' => (string) $hubId,
            'name' => $area,
            'deployment' => 'barangay',
            'relay_hub_id' => null,
        ];
        $sitrep['source_snapshot']['hub_node']['snapshot']['name'] = $area;
        $sitrep['summary']['supporting_metrics']['team_assignments'] = 1;
        $sitrep['summary']['status_counts'] = [
            'Active' => 1,
            'Deferred' => 0,
        ];
        $sitrep['summary']['gap_cards'] = [
            [
                'label' => 'People at Risk',
                'value' => '1 active report',
                'note' => 'Life-safety signals require leadership visibility.',
            ],
            [
                'label' => 'Access to Help',
                'value' => 'No current access constraint reported',
                'note' => 'No blocked or limited route report is present in configured road/access fields.',
            ],
            [
                'label' => 'Response Progress',
                'value' => '1 open / 0 addressed',
                'note' => 'One report remains open.',
            ],
        ];
        $sitrep['summary']['accomplishment_cards'] = [
            [
                'label' => 'People Helped',
                'value' => '1 family assisted',
                'note' => 'Resolved support remains visible for leadership.',
            ],
            [
                'label' => 'Teams / Resources Deployed',
                'value' => '1 completed team assignments; 2 resource units',
                'note' => 'Completed team assignments and resources are no longer current demand.',
            ],
        ];
        $sitrep['summary']['priority_watch_items'] = [
            'River level monitoring',
        ];
        $sitrep['summary']['decision_points'] = [
            [
                'title' => 'Life safety',
                'body' => $area.' may need prioritization.',
            ],
        ];
        $sitrep['situation'] = [
            'current_operating_picture' => [
                'open_reports' => 1,
                'active_reports' => 1,
                'deferred_reports' => 0,
                'current_assignments' => 1,
                'current_resource_units' => 2,
            ],
            'locations' => [
                ['area' => $area, 'count' => 1],
            ],
            'incident_types' => [
                ['type' => $type, 'count' => 1],
            ],
            'concern_groups' => [
                [
                    'concern' => 'Life safety',
                    'open_reports' => 1,
                    'areas' => [$area],
                    'main_signals' => $type,
                    'current_assignments' => 1,
                    'resource_units' => 2,
                ],
            ],
            'decision_points' => [
                [
                    'title' => 'Life safety',
                    'body' => $area.' field reports should be reviewed before cross-hub deployment.',
                ],
            ],
        ];
        $sitrep['actions'] = [
            'deployment_groups' => [
                [
                    'category' => 'Barangay Teams',
                    'team' => $team,
                    'incident_ids' => [$hubId * 10],
                    'status_counts' => [
                        'assigned' => $hubId === 12 ? 1 : 0,
                        'en_route' => $hubId === 12 ? 0 : 1,
                    ],
                    'reports_covered' => 1,
                    'total_assignments' => 1,
                ],
            ],
            'timing_rows' => [
                ['incident_id' => $hubId * 10, 'team' => $team, 'current_status' => $hubId === 12 ? 'Assigned' : 'En Route'],
            ],
            'assignments' => [
                [
                    'incident_id' => $hubId * 10,
                    'team' => $team,
                    'status' => $hubId === 12 ? 'Assigned' : 'En Route',
                    'assigned_at' => $hubId === 12 ? '2026-05-29T15:16:00+08:00' : '2026-05-29T15:45:00+08:00',
                    'enroute_at' => $hubId === 12 ? null : '2026-05-29T16:16:00+08:00',
                ],
            ],
        ];
        $sitrep['needs'] = [
            'category_groups' => [
                ['category' => 'Transport', 'quantity_requested' => $hubId === 12 ? 2 : 3, 'resources' => [$resource]],
            ],
            'items' => [
                [
                    'resource' => $resource,
                    'category' => 'Transport',
                    'quantity_requested' => $hubId === 12 ? 2 : 3,
                    'incident_count' => 1,
                ],
            ],
        ];
        $sitrep['population'] = [
            'numeric_total' => 1,
            'citizens_assisted' => $hubId === 12 ? 1 : 0,
            'record_count' => 1,
            'population_groups' => [
                [
                    'population_signal' => 'People injured',
                    'reports' => 1,
                    'people_or_families' => '1 record',
                    'notes' => 'Details reported; verification required.',
                ],
                [
                    'population_signal' => 'Affected family',
                    'reports' => 1,
                    'people_or_families' => $hubId === 12 ? '1 family / 6 people' : '2 families / 9 people',
                    'notes' => '1 displacement signal',
                    'breakdowns' => [
                        ['breakdown' => 'Children', 'count' => $hubId === 12 ? 3 : 4],
                        ['breakdown' => 'Senior citizens', 'count' => $hubId === 12 ? 1 : 2],
                        ['breakdown' => 'PWD', 'count' => $hubId === 12 ? 1 : 2],
                        ['breakdown' => 'Pregnant', 'count' => 1],
                    ],
                ],
            ],
        ];
        $sitrep['damage'] = [
            'items' => [
                [
                    'label' => 'Infrastructure damage',
                    'value' => 'minor damage',
                    'source' => ['asset_type' => 'road', 'damage_level' => 'minor'],
                ],
            ],
        ];
        $sitrep['gaps'] = [
            'items' => [
                [
                    'category' => 'Movement',
                    'title' => $gapTitle,
                    'body' => 'Route needs field verification.',
                    'evidence' => '1 route affected.',
                    'items' => [
                        ['route_location' => $area.' access road', 'status' => 'limited', 'obstruction_type' => 'debris', 'cleared' => 'No'],
                    ],
                ],
            ],
        ];

        foreach (['summary', 'situation', 'damage', 'population', 'actions', 'needs', 'gaps', 'data_quality'] as $section) {
            $sitrep[$section] = [
                'rollup' => $sitrep[$section],
                'items' => [[
                    'location' => $location,
                    'data' => $sitrep[$section],
                ]],
            ];
        }

        $sitrep['source_snapshot'] = [
            'rollup' => $sitrep['source_snapshot'],
            'items' => [[
                'location' => $location,
                'data' => $sitrep['source_snapshot'],
            ]],
        ];

        return $sitrep;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
