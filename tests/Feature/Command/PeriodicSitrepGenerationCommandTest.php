<?php

namespace Tests\Feature\Command;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PeriodicSitrepGenerationCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'https://relay.pbb.ph/hub.json' => Http::response([
                'name' => 'Guadalupe, CEBU CITY, CEBU',
                'deployment' => 'barangay',
                'relay_hub_id' => '072217029',
                'snapshot_hash' => 'test-hash',
            ]),
            'relay.pbb.ph/hub.json' => Http::response([
                'name' => 'Guadalupe, CEBU CITY, CEBU',
                'deployment' => 'barangay',
                'relay_hub_id' => '072217029',
                'snapshot_hash' => 'test-hash',
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_critical_alert_generates_last_completed_fifteen_minute_private_draft_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 10:17:00', 'Asia/Manila'));
        User::factory()->create([
            'role' => UserRole::Command,
        ]);
        app(SettingsService::class)->set('alert_level', 'Critical');

        $this->artisan('app:generate-periodic-sitrep')
            ->assertSuccessful();

        $this->assertDatabaseCount('sitrep_reports', 1);
        $report = SitrepReport::query()->firstOrFail();

        $this->assertSame('PBB Hotline Critical SITREP - 2026-05-29 10:15', $report->title);
        $this->assertSame('draft', $report->status);
        $this->assertSame('private', $report->visibility);
        $this->assertNull($report->prepared_by_user_id);
        $this->assertSame('System Generated', $report->source_snapshot_json['generation']['prepared_by_label']);
        $this->assertSame('Guadalupe, CEBU CITY, CEBU', $report->coverage_area);
        $this->assertSame('2026-05-29 10:00:00', $report->period_started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-29 10:15:00', $report->period_ended_at?->format('Y-m-d H:i:s'));

        $this->artisan('app:generate-periodic-sitrep')
            ->assertSuccessful();

        $this->assertDatabaseCount('sitrep_reports', 1);
    }

    public function test_elevated_alert_uses_hourly_window_and_relay_hub_coverage_area(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 13:05:00', 'Asia/Manila'));
        User::factory()->create([
            'role' => UserRole::Command,
        ]);
        $settings = app(SettingsService::class);
        $settings->set('alert_level', 'Elevated');

        $this->artisan('app:generate-periodic-sitrep')
            ->assertSuccessful();

        $report = SitrepReport::query()->firstOrFail();

        $this->assertSame('Guadalupe, CEBU CITY, CEBU', $report->coverage_area);
        $this->assertSame('2026-05-29 12:00:00', $report->period_started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-29 13:00:00', $report->period_ended_at?->format('Y-m-d H:i:s'));
    }

    public function test_normal_alert_uses_four_hour_default_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 13:05:00', 'Asia/Manila'));
        User::factory()->create([
            'role' => UserRole::Command,
        ]);
        app(SettingsService::class)->set('alert_level', 'Normal');

        $this->artisan('app:generate-periodic-sitrep')
            ->assertSuccessful();

        $report = SitrepReport::query()->firstOrFail();

        $this->assertSame('2026-05-29 08:00:00', $report->period_started_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-29 12:00:00', $report->period_ended_at?->format('Y-m-d H:i:s'));
    }

    public function test_disabled_periodic_generation_skips_without_force(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 10:17:00', 'Asia/Manila'));
        User::factory()->create([
            'role' => UserRole::Command,
        ]);
        app(SettingsService::class)->set('sitrep_periodic_generation_enabled', false);

        $this->artisan('app:generate-periodic-sitrep')
            ->assertSuccessful();

        $this->assertDatabaseCount('sitrep_reports', 0);

        $this->artisan('app:generate-periodic-sitrep --force')
            ->assertSuccessful();

        $this->assertDatabaseCount('sitrep_reports', 1);
    }

    public function test_periodic_command_does_not_accept_manual_coverage_override(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 13:05:00', 'Asia/Manila'));
        User::factory()->create([
            'role' => UserRole::Command,
        ]);

        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->expectExceptionMessage('The "--coverage-area" option does not exist.');

        $this->artisan('app:generate-periodic-sitrep --coverage-area=Cebu City')
            ->run();
    }

    public function test_periodic_worker_runs_generator_once_when_max_runs_is_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 10:17:00', 'Asia/Manila'));
        app(SettingsService::class)->set('sitrep_periodic_generation_enabled', false);

        $this->artisan('app:work-periodic-sitrep --sleep=1 --max-runs=1')
            ->expectsOutput('Periodic SITREP worker started. Checking every 1 seconds.')
            ->expectsOutput('Periodic SITREP generation is disabled.')
            ->expectsOutput('Periodic SITREP worker stopped.')
            ->assertSuccessful();

        $this->assertDatabaseCount('sitrep_reports', 0);
    }
}
