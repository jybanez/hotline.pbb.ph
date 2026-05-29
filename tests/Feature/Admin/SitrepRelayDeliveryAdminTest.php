<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SitrepRelayDeliveryAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_delivery_rows_with_derived_superseded_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $superseded = $this->createSitrep(sequence: 1, generatedAt: '2026-05-30 08:00:00');
        $current = $this->createSitrep(sequence: 2, generatedAt: '2026-05-30 09:00:00');

        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $superseded->id,
            'status' => SitrepRelayDelivery::STATUS_FAILED,
        ]);
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $current->id,
            'status' => SitrepRelayDelivery::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/sitrep-relay-deliveries')
            ->assertOk()
            ->assertJsonPath('latest_sitrep_id', $current->id)
            ->assertJsonPath('items.0.sitrep_report_id', $current->id)
            ->assertJsonPath('items.0.display_status', 'pending')
            ->assertJsonPath('items.0.is_retryable', true)
            ->assertJsonPath('items.1.sitrep_report_id', $superseded->id)
            ->assertJsonPath('items.1.display_status', 'superseded')
            ->assertJsonPath('items.1.status_note', 'Intentionally not retried because a newer SITREP has superseded this report.')
            ->assertJsonPath('items.1.is_retryable', false);
    }

    public function test_admin_cannot_retry_intentionally_superseded_delivery(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $superseded = $this->createSitrep(sequence: 1, generatedAt: '2026-05-30 08:00:00');
        $this->createSitrep(sequence: 2, generatedAt: '2026-05-30 09:00:00');
        $delivery = SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $superseded->id,
            'status' => SitrepRelayDelivery::STATUS_FAILED,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/sitrep-relay-deliveries/{$delivery->id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This SITREP has been intentionally superseded by a newer report and is not relay-eligible.');
    }

    private function createSitrep(int $sequence, string $generatedAt): SitrepReport
    {
        $generated = Carbon::parse($generatedAt, 'Asia/Manila');

        return SitrepReport::query()->create([
            'sequence_number' => $sequence,
            'title' => sprintf('SITREP #%04d', $sequence),
            'coverage_area' => 'Guadalupe, Cebu City, Cebu',
            'period_started_at' => $generated->copy()->subHour(),
            'period_ended_at' => $generated,
            'generated_at' => $generated,
            'published_at' => null,
            'status' => 'draft',
            'visibility' => 'private',
            'alert_level' => 'Normal',
            'prepared_by_user_id' => null,
            'reviewed_by_user_id' => null,
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
