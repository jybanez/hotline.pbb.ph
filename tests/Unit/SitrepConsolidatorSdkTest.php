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
        $this->assertSame('consolidated', $result->sitrep['source_snapshot']['generation']['type']);
        $this->assertSame('System Generated', $result->sitrep['source_snapshot']['generation']['prepared_by_label']);
        $this->assertSame('draft', $result->sitrep['status']);
        $this->assertSame('private', $result->sitrep['visibility']);
        $this->assertSame('2026-05-29T17:00:00+08:00', $result->sitrep['period_started_at']);
        $this->assertSame('2026-05-29T17:15:00+08:00', $result->sitrep['period_ended_at']);
        $this->assertSame('Consolidator preserves source totals for planning awareness; validation remains app-owned.', $result->sitrep['population']['rollup']['confidence_note']);
        $this->assertSame('Source SITREPs', $result->sitrep['actions']['rollup']['deployment_groups'][0]['category']);
        $this->assertSame('Consolidated Sources', $result->sitrep['actions']['rollup']['deployment_groups'][0]['team']);
        $this->assertSame(3, $result->sitrep['actions']['rollup']['deployment_groups'][0]['status_counts']['assigned']);
        $this->assertCount(3, $result->sitrep['source_snapshot']['source_sitreps']);
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
        ], $result->sitrep['source_snapshot']['incident_coordinates']);
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
