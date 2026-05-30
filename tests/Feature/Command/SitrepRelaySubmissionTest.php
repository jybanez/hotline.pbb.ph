<?php

namespace Tests\Feature\Command;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Jobs\SubmitSitrepRelayDelivery;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SitrepRelaySubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitrep_creation_creates_relay_delivery_outbox_row(): void
    {
        Queue::fake();
        Http::fake([
            'https://relay.pbb.ph/hub.json' => Http::response($this->hubJson()),
            'relay.pbb.ph/hub.json' => Http::response($this->hubJson()),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-30 08:15:00', 'Asia/Manila'));
        app(SettingsService::class)->set('alert_level', 'Normal');

        $this->artisan('app:generate-periodic-sitrep --force')
            ->assertSuccessful();

        $report = SitrepReport::query()->firstOrFail();

        $this->assertDatabaseHas('sitrep_relay_deliveries', [
            'sitrep_report_id' => $report->id,
            'status' => SitrepRelayDelivery::STATUS_PENDING,
        ]);

        Queue::assertPushed(SubmitSitrepRelayDelivery::class);
    }

    public function test_latest_unsent_sitrep_is_submitted_to_relay_with_full_json_payload(): void
    {
        app(SettingsService::class)->set('relay_url', 'https://relay.pbb.ph');
        app(SettingsService::class)->set('relay_token', 'test-relay-key');
        app(SettingsService::class)->set('relay_source_system', 'pbb.hotline');
        app(SettingsService::class)->set('relay_target_systems', "sitrep.ingestor\nsupport.dispatch");

        $report = $this->createSitrep(sequence: 63, generatedAt: '2026-05-30 09:00:00');
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $report->id,
            'status' => SitrepRelayDelivery::STATUS_PENDING,
        ]);

        Http::fake([
            'https://relay.pbb.ph/api/v1/messages' => Http::response([
                'success' => true,
                'relay_id' => '01HZTESTSITREP000000000001',
                'message_id' => '01KSX6D7SXE73HTFVWW6WGXN9X',
                'status' => 'queued',
                'deliveries_count' => 1,
                'deliveries' => [],
            ], 201),
        ]);
        Log::spy();

        $this->artisan('app:submit-latest-sitrep-to-relay')
            ->assertSuccessful();

        Http::assertSent(function ($request) use ($report): bool {
            return $request->url() === 'https://relay.pbb.ph/api/v1/messages'
                && $request->hasHeader('X-Relay-Key', 'test-relay-key')
                && $request->hasHeader('Connection', 'close')
                && $request['source_system'] === 'pbb.hotline'
                && $request['target_systems'] === ['sitrep.ingestor', 'support.dispatch']
                && $request['message_type'] === 'sitrep.record'
                && $request['payload_format'] === 'json'
                && $request['reference_id'] === (string) $report->id
                && $request['payload']['id'] === $report->id
                && $request['payload']['sequence_number'] === 63
                && $request['payload']['source_snapshot']['hub_node']['snapshot']['hub_id'] === '072217029';
        });

        $this->assertDatabaseHas('sitrep_relay_deliveries', [
            'sitrep_report_id' => $report->id,
            'status' => SitrepRelayDelivery::STATUS_SENT,
            'relay_id' => '01HZTESTSITREP000000000001',
            'relay_message_id' => '01KSX6D7SXE73HTFVWW6WGXN9X',
        ]);
        Log::shouldHaveReceived('info')
            ->with('SITREP Relay submission accepted.', \Mockery::on(
                fn (array $context): bool => $context['sitrep_report_id'] === $report->id
                    && $context['relay_id'] === '01HZTESTSITREP000000000001'
                    && $context['relay_message_id'] === '01KSX6D7SXE73HTFVWW6WGXN9X'
                    && $context['source_system'] === 'pbb.hotline'
                    && $context['target_systems'] === ['sitrep.ingestor', 'support.dispatch']
            ))
            ->once();
    }

    public function test_failed_relay_submission_writes_laravel_log_entry(): void
    {
        app(SettingsService::class)->set('relay_url', 'https://relay.pbb.ph');
        app(SettingsService::class)->set('relay_token', 'test-relay-key');

        $report = $this->createSitrep(sequence: 64, generatedAt: '2026-05-30 10:00:00');
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $report->id,
            'status' => SitrepRelayDelivery::STATUS_PENDING,
        ]);

        Http::fake([
            'https://relay.pbb.ph/api/v1/messages' => Http::response([
                'message' => 'No active handler matched target system.',
            ], 422),
        ]);
        Log::spy();

        $this->artisan('app:submit-latest-sitrep-to-relay')
            ->assertSuccessful();

        $this->assertDatabaseHas('sitrep_relay_deliveries', [
            'sitrep_report_id' => $report->id,
            'status' => SitrepRelayDelivery::STATUS_FAILED,
        ]);
        Log::shouldHaveReceived('warning')
            ->with('SITREP Relay submission failed.', \Mockery::on(
                fn (array $context): bool => $context['sitrep_report_id'] === $report->id
                    && $context['reason'] === 'relay_rejected'
                    && $context['http_status'] === 422
                    && str_contains($context['error'], 'Relay rejected SITREP handoff with HTTP 422')
            ))
            ->once();
        Log::shouldHaveReceived('warning')
            ->with('SITREP Relay delivery marked failed.', \Mockery::on(
                fn (array $context): bool => $context['sitrep_report_id'] === $report->id
                    && str_contains($context['error'], 'Relay rejected SITREP handoff with HTTP 422')
            ))
            ->once();
    }

    public function test_retry_command_skips_intentionally_superseded_failed_deliveries(): void
    {
        app(SettingsService::class)->set('relay_url', 'https://relay.pbb.ph');
        app(SettingsService::class)->set('relay_token', 'test-relay-key');

        $superseded = $this->createSitrep(sequence: 1, generatedAt: '2026-05-30 08:00:00');
        $current = $this->createSitrep(sequence: 2, generatedAt: '2026-05-30 09:00:00');

        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $superseded->id,
            'status' => SitrepRelayDelivery::STATUS_FAILED,
            'last_error' => 'Local Relay was unavailable.',
        ]);
        SitrepRelayDelivery::query()->create([
            'sitrep_report_id' => $current->id,
            'status' => SitrepRelayDelivery::STATUS_FAILED,
            'last_error' => 'Local Relay was unavailable.',
        ]);

        Http::fake([
            'https://relay.pbb.ph/api/v1/messages' => Http::response([
                'success' => true,
                'relay_id' => '01HZCURRENT000000000000001',
                'message_id' => 456,
                'status' => 'queued',
                'deliveries_count' => 1,
                'deliveries' => [],
            ], 201),
        ]);

        $this->artisan('app:submit-latest-sitrep-to-relay')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['reference_id'] === (string) $current->id);

        $this->assertDatabaseHas('sitrep_relay_deliveries', [
            'sitrep_report_id' => $superseded->id,
            'status' => SitrepRelayDelivery::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('sitrep_relay_deliveries', [
            'sitrep_report_id' => $current->id,
            'status' => SitrepRelayDelivery::STATUS_SENT,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hubJson(): array
    {
        return [
            'name' => 'Guadalupe, Cebu City, Cebu',
            'deployment' => 'barangay',
            'relay_hub_id' => '072217029',
            'hub_id' => '072217029',
            'snapshot_hash' => 'test-hash',
        ];
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
            'alert_level' => $sequence === 2 ? 'Critical' : 'Normal',
            'prepared_by_user_id' => null,
            'reviewed_by_user_id' => null,
            'summary_json' => ['headline' => 'Generated summary'],
            'situation_json' => ['narrative' => 'Situation narrative'],
            'damage_json' => [],
            'population_json' => [],
            'actions_json' => [],
            'needs_json' => [],
            'gaps_json' => [],
            'source_snapshot_json' => [
                'hub_node' => [
                    'snapshot' => [
                        'hub_id' => '072217029',
                        'deployment' => 'barangay',
                    ],
                ],
            ],
            'privacy_redactions_json' => [],
            'data_quality_json' => [],
        ]);
    }
}
