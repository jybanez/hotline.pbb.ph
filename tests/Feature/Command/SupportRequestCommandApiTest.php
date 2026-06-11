<?php

namespace Tests\Feature\Command;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Domain\SupportRequests\Models\SupportRequest;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportRequestCommandApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_command_support_request_endpoint_validates_modal_fields(): void
    {
        $command = User::factory()->create(['role' => UserRole::Command]);

        $this->actingAs($command)
            ->postJson('/api/command/support-requests', [
                'sitrep_report_id' => 999,
                'urgency' => 'severe',
                'requested_assistance' => '',
                'quantity' => 'many',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sitrep_report_id',
                'urgency',
                'requested_assistance',
                'quantity',
            ]);
    }

    public function test_command_user_can_create_and_submit_support_request_to_relay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 10:15:00', 'Asia/Manila'));
        $this->configureRelay();
        $command = User::factory()->create([
            'name' => 'Command Lead',
            'role' => UserRole::Command,
        ]);
        $sitrep = $this->createSitrep();

        Http::fake([
            'https://relay.pbb.ph/api/v1/messages' => Http::response([
                'success' => true,
                'relay_id' => '01JCOMMANDSUPPORT0000000001',
                'message_id' => '01JCOMMANDMESSAGE00000001',
                'status' => 'queued',
                'deliveries_count' => 1,
            ], 201),
        ]);

        $response = $this->actingAs($command)
            ->postJson('/api/command/support-requests', $this->payload($sitrep))
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('support_request.status', SupportRequest::STATUS_RELAY_ACCEPTED)
            ->assertJsonPath('support_request.relay_delivery_status', SupportRequest::RELAY_ACCEPTED)
            ->assertJsonPath('support_request.relay_id', '01JCOMMANDSUPPORT0000000001')
            ->assertJsonPath('support_request.relay_message_id', '01JCOMMANDMESSAGE00000001')
            ->assertJsonPath('support_request.relay_last_error', null);

        $localRequestId = $response->json('support_request.local_request_id');

        $this->assertDatabaseHas('support_requests', [
            'local_request_id' => $localRequestId,
            'requested_assistance' => 'Rescue and extraction support',
            'requested_capability' => 'Rescue and Extraction',
            'quantity' => 2,
            'quantity_unit' => 'teams',
            'requester_user_id' => $command->id,
            'requester_name' => 'Command Lead',
            'requester_role' => UserRole::Command->value,
            'sitrep_report_id' => $sitrep->id,
            'sitrep_section' => 'gaps',
            'sitrep_evidence_ref' => 'gaps.open_needs.1',
        ]);

        Http::assertSent(function ($request) use ($localRequestId): bool {
            return $request->url() === 'https://relay.pbb.ph/api/v1/messages'
                && $request['source_system'] === 'hotline.command'
                && $request['message_type'] === 'support.request'
                && $request['reference_id'] === $localRequestId
                && $request['targets'] === [[
                    'id' => '11',
                    'systems' => ['support.dispatch'],
                ]]
                && $request['payload']['request']['requested_assistance'] === 'Rescue and extraction support'
                && $request['payload']['sitrep']['evidence_ref'] === 'gaps.open_needs.1'
                && $request['payload']['gap']['title'] === 'Resource supply not confirmed'
                && $request['payload']['evidence_row']['category'] === 'Rescue and Extraction';
        });
    }

    public function test_command_support_request_returns_failed_relay_status_when_handoff_fails(): void
    {
        $this->configureRelay();
        $command = User::factory()->create(['role' => UserRole::Command]);
        $sitrep = $this->createSitrep([
            'uplinks' => [
                ['hub' => ['id' => null]],
            ],
        ]);

        Http::fake();

        $response = $this->actingAs($command)
            ->postJson('/api/command/support-requests', $this->payload($sitrep))
            ->assertCreated()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('support_request.status', SupportRequest::STATUS_FAILED)
            ->assertJsonPath('support_request.relay_delivery_status', SupportRequest::RELAY_FAILED)
            ->assertJsonPath('support_request.relay_last_error', 'Relay target hubs are not available from hub.json uplinks.');

        Http::assertNothingSent();

        $this->assertDatabaseHas('support_requests', [
            'local_request_id' => $response->json('support_request.local_request_id'),
            'relay_delivery_status' => SupportRequest::RELAY_FAILED,
            'relay_last_error' => 'Relay target hubs are not available from hub.json uplinks.',
        ]);
    }

    public function test_command_support_request_endpoint_requires_command_role(): void
    {
        $this->postJson('/api/command/support-requests', [])
            ->assertUnauthorized();

        $citizen = User::factory()->create(['role' => UserRole::Citizen]);

        $this->actingAs($citizen)
            ->postJson('/api/command/support-requests', [])
            ->assertRedirect('/unauthorized');
    }

    private function configureRelay(): void
    {
        app(SettingsService::class)->set('relay_url', 'https://relay.pbb.ph');
        app(SettingsService::class)->set('relay_token', 'test-relay-key');
        app(SettingsService::class)->set('support_request_relay_source_system', 'hotline.command');
        app(SettingsService::class)->set('support_request_relay_target_systems', 'support.dispatch');
    }

    /**
     * @param  array<string, mixed>|null  $sourceSnapshot
     */
    private function createSitrep(?array $sourceSnapshot = null): SitrepReport
    {
        $generated = Carbon::parse('2026-06-11 09:00:00', 'Asia/Manila');

        return SitrepReport::query()->create([
            'sequence_number' => 61,
            'title' => 'Daily SITREP - 2026-06-11',
            'coverage_area' => 'Guadalupe, Cebu City, Cebu',
            'period_started_at' => $generated->copy()->subHours(4),
            'period_ended_at' => $generated,
            'generated_at' => $generated,
            'status' => 'draft',
            'visibility' => 'private',
            'alert_level' => 'Elevated',
            'summary_json' => ['rollup' => ['headline' => 'Test summary']],
            'source_snapshot_json' => [
                'rollup' => [
                    'hub_node' => [
                        'snapshot' => $sourceSnapshot ?? $this->hubSnapshot(),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hubSnapshot(): array
    {
        return [
            'name' => 'Guadalupe, Cebu City, Cebu',
            'deployment' => 'barangay',
            'relay_hub_id' => '072217029',
            'hub_id' => '072217029',
            'uplinks' => [[
                'id' => 29,
                'hub' => [
                    'id' => 11,
                    'name' => 'Cebu City, Cebu',
                    'deployment' => 'city',
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SitrepReport $sitrep): array
    {
        return [
            'sitrep_report_id' => $sitrep->id,
            'sitrep_section' => 'gaps',
            'sitrep_evidence_ref' => 'gaps.open_needs.1',
            'urgency' => 'urgent',
            'requested_assistance' => 'Rescue and extraction support',
            'requested_capability' => 'Rescue and Extraction',
            'quantity' => 2,
            'quantity_unit' => 'teams',
            'staging_notes' => 'Stage near Barangay Hall.',
            'command_notes' => 'Request approved by command.',
            'gap' => [
                'title' => 'Resource supply not confirmed',
                'category' => 'Operational constraint',
                'type' => 'open_needs',
            ],
            'evidence_row' => [
                'category' => 'Rescue and Extraction',
                'quantity' => 2,
                'resources' => ['Rescue Team'],
            ],
            'incident_refs' => [[
                'id' => 234,
                'public_code' => 'A000234',
            ]],
        ];
    }
}
