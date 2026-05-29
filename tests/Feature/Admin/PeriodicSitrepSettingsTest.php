<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PeriodicSitrepSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_settings_exposes_periodic_sitrep_status_for_current_alert_level(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-29 13:05:00', 'Asia/Manila'));
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);
        app(SettingsService::class)->set('alert_level', 'Critical');

        SitrepReport::query()->create([
            'sequence_number' => 7,
            'title' => 'PBB Hotline Critical SITREP - 2026-05-29 13:00',
            'coverage_area' => 'Guadalupe, CEBU CITY, CEBU',
            'period_started_at' => Carbon::parse('2026-05-29 12:45:00', 'Asia/Manila'),
            'period_ended_at' => Carbon::parse('2026-05-29 13:00:00', 'Asia/Manila'),
            'generated_at' => Carbon::parse('2026-05-29 13:01:00', 'Asia/Manila'),
            'status' => 'draft',
            'visibility' => 'private',
            'alert_level' => 'Critical',
            'prepared_by_user_id' => null,
            'source_snapshot_json' => [
                'generation' => [
                    'type' => 'system',
                    'prepared_by_label' => 'System Generated',
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('meta.sitrep_periodic.enabled', true)
            ->assertJsonPath('meta.sitrep_periodic.alert_level', 'Critical')
            ->assertJsonPath('meta.sitrep_periodic.interval_minutes', 15)
            ->assertJsonPath('meta.sitrep_periodic.prepared_by_label', 'System Generated')
            ->assertJsonPath('meta.sitrep_periodic.coverage_source', 'relay_hub_json')
            ->assertJsonPath('meta.sitrep_periodic.latest_auto_sitrep.sequence_number', 7)
            ->assertJsonPath('meta.sitrep_periodic.latest_auto_sitrep.coverage_area', 'Guadalupe, CEBU CITY, CEBU')
            ->assertJsonFragment([
                'key' => 'sitrep_periodic_generation_enabled',
                'value' => true,
            ]);
    }

    public function test_admin_can_disable_periodic_sitrep_generation_setting(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                'items' => [
                    ['key' => 'sitrep_periodic_generation_enabled', 'value' => false],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('meta.sitrep_periodic.enabled', false)
            ->assertJsonFragment([
                'key' => 'sitrep_periodic_generation_enabled',
                'value' => false,
            ]);
    }
}
