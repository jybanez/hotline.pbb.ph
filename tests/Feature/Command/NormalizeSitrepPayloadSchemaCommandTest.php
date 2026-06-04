<?php

namespace Tests\Feature\Command;

use App\Domain\Sitreps\Models\SitrepReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NormalizeSitrepPayloadSchemaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_legacy_sitrep_sections_to_rollup_items_schema(): void
    {
        $report = $this->legacySitrepReport();

        $this->artisan('app:normalize-sitrep-payload-schema --dry-run')
            ->expectsOutput('Would normalize 1 SITREP report for payload schema v2.')
            ->assertSuccessful();

        $this->assertArrayNotHasKey('rollup', $report->refresh()->summary_json);

        $this->artisan('app:normalize-sitrep-payload-schema')
            ->expectsOutput('Normalized 1 SITREP report for payload schema v2.')
            ->assertSuccessful();

        $report->refresh();

        $this->assertSame('Legacy headline', $report->summary_json['rollup']['headline']);
        $this->assertSame('Legacy headline', $report->summary_json['items'][0]['data']['headline']);
        $this->assertSame('072217029', $report->summary_json['items'][0]['location']['id']);
        $this->assertSame('Guadalupe, Cebu City, Cebu', $report->summary_json['items'][0]['location']['name']);
        $this->assertSame('barangay', $report->summary_json['items'][0]['location']['deployment']);
        $this->assertSame('System Generated', $report->source_snapshot_json['rollup']['generation']['prepared_by_label']);
    }

    private function legacySitrepReport(): SitrepReport
    {
        return SitrepReport::query()->create([
            'sequence_number' => 1,
            'title' => 'Legacy SITREP',
            'coverage_area' => 'Guadalupe, Cebu City, Cebu',
            'period_started_at' => Carbon::parse('2026-05-30 00:00:00', 'Asia/Manila'),
            'period_ended_at' => Carbon::parse('2026-05-30 23:59:59', 'Asia/Manila'),
            'generated_at' => Carbon::parse('2026-05-30 09:00:00', 'Asia/Manila'),
            'status' => 'draft',
            'visibility' => 'private',
            'alert_level' => 'Normal',
            'summary_json' => [
                'headline' => 'Legacy headline',
            ],
            'situation_json' => [],
            'damage_json' => [],
            'population_json' => [],
            'actions_json' => [],
            'needs_json' => [],
            'gaps_json' => [],
            'source_snapshot_json' => [
                'generation' => [
                    'prepared_by_label' => 'System Generated',
                ],
                'hub_node' => [
                    'snapshot' => [
                        'hub_id' => '072217029',
                        'name' => 'Guadalupe, Cebu City, Cebu',
                        'deployment' => 'barangay',
                    ],
                ],
            ],
            'privacy_redactions_json' => [],
            'data_quality_json' => [],
        ]);
    }
}
