<?php

namespace Tests\Feature\Command;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClearSitrepsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_to_clear_sitreps_without_force(): void
    {
        $this->createSitrep(sequence: 1);

        $this->artisan('app:clear-sitreps --all')
            ->expectsOutput('Refusing to run without --force.')
            ->assertFailed();

        $this->assertDatabaseCount('sitrep_reports', 1);
    }

    public function test_clears_all_sitreps_and_relay_deliveries(): void
    {
        $first = $this->createSitrep(sequence: 1, status: 'draft');
        $second = $this->createSitrep(sequence: 2, status: 'published');
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $first->id,
            'status' => SitrepRelayDelivery::STATUS_PENDING,
        ]);
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $second->id,
            'status' => SitrepRelayDelivery::STATUS_SENT,
        ]);

        $this->artisan('app:clear-sitreps --all --force')
            ->expectsOutput('sitrep_relay_deliveries: 2')
            ->expectsOutput('sitrep_reports: 2')
            ->expectsOutput('SITREP cleanup finished.')
            ->assertSuccessful();

        $this->assertDatabaseCount('sitrep_reports', 0);
        $this->assertDatabaseCount('sitrep_relay_deliveries', 0);
    }

    public function test_clears_sitreps_by_status(): void
    {
        $draft = $this->createSitrep(sequence: 1, status: 'draft');
        $published = $this->createSitrep(sequence: 2, status: 'published');
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $draft->id,
            'status' => SitrepRelayDelivery::STATUS_PENDING,
        ]);
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $published->id,
            'status' => SitrepRelayDelivery::STATUS_SENT,
        ]);

        $this->artisan('app:clear-sitreps --all --status=draft --force')
            ->expectsOutput('sitrep_relay_deliveries: 1')
            ->expectsOutput('sitrep_reports: 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sitrep_reports', ['id' => $draft->id]);
        $this->assertDatabaseHas('sitrep_reports', ['id' => $published->id]);
        $this->assertDatabaseMissing('sitrep_relay_deliveries', ['sitrep_report_id' => $draft->id]);
        $this->assertDatabaseHas('sitrep_relay_deliveries', ['sitrep_report_id' => $published->id]);
    }

    public function test_rejects_invalid_status_scope(): void
    {
        $this->createSitrep(sequence: 1);

        $this->artisan('app:clear-sitreps --all --status=archived --force')
            ->expectsOutput('Invalid --status value. Expected draft or published.')
            ->assertFailed();

        $this->assertDatabaseCount('sitrep_reports', 1);
    }

    private function createSitrep(int $sequence, string $status = 'draft'): SitrepReport
    {
        return SitrepReport::query()->create([
            'sequence_number' => $sequence,
            'title' => sprintf('SITREP %d', $sequence),
            'coverage_area' => 'Guadalupe, Cebu City, Cebu',
            'period_started_at' => Carbon::parse('2026-05-30 00:00:00', 'Asia/Manila'),
            'period_ended_at' => Carbon::parse('2026-05-30 23:59:59', 'Asia/Manila'),
            'generated_at' => Carbon::parse('2026-05-30 09:00:00', 'Asia/Manila'),
            'published_at' => $status === 'published' ? Carbon::parse('2026-05-30 09:05:00', 'Asia/Manila') : null,
            'status' => $status,
            'visibility' => $status === 'published' ? 'public' : 'private',
            'alert_level' => 'Normal',
            'summary_json' => [],
            'situation_json' => [],
            'damage_json' => [],
            'population_json' => [],
            'actions_json' => [],
            'needs_json' => [],
            'gaps_json' => [],
            'source_snapshot_json' => [],
            'privacy_redactions_json' => [],
            'data_quality_json' => [],
        ]);
    }
}
