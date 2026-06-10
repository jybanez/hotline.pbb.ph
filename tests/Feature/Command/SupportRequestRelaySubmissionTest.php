<?php

namespace Tests\Feature\Command;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Domain\SupportRequests\Models\SupportRequest;
use App\Domain\Users\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\SupportRequests\SupportRequestCreationService;
use App\Support\SupportRequests\SupportRequestRelaySubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SupportRequestRelaySubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_approved_support_request_is_persisted_with_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 08:30:00', 'Asia/Manila'));

        $requester = $this->createCommandUser();
        $sitrep = $this->createSitrep();

        $supportRequest = app(SupportRequestCreationService::class)->create($this->requestPayload($sitrep), $requester);

        $this->assertMatchesRegularExpression('/^srq_[0-9a-z]{26}$/', $supportRequest->local_request_id);
        $this->assertSame($supportRequest->local_request_id, $supportRequest->correlation_id);
        $this->assertSame(SupportRequest::STATUS_REQUESTED, $supportRequest->status);
        $this->assertSame(SupportRequest::RELAY_PENDING, $supportRequest->relay_delivery_status);

        $this->assertDatabaseHas('support_requests', [
            'id' => $supportRequest->id,
            'requested_assistance' => 'Rescue and extraction support',
            'requested_capability' => 'rescue_and_extraction',
            'quantity' => 2,
            'quantity_unit' => 'teams',
            'requester_user_id' => $requester->id,
            'source_system' => 'hotline.command',
            'source_hub_id' => '072217029',
            'source_relay_hub_id' => '072217029',
            'sitrep_report_id' => $sitrep->id,
            'sitrep_evidence_ref' => 'gaps.resource_supply.1',
        ]);

        $this->assertDatabaseHas('support_request_histories', [
            'support_request_id' => $supportRequest->id,
            'event_type' => 'support.request.created',
            'status' => SupportRequest::STATUS_REQUESTED,
            'actor_name' => 'Command User',
        ]);
    }

    public function test_support_request_is_submitted_to_relay_with_canonical_targets_and_compact_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 09:00:00', 'Asia/Manila'));
        app(SettingsService::class)->set('relay_url', 'https://relay.pbb.ph');
        app(SettingsService::class)->set('relay_token', 'test-relay-key');
        app(SettingsService::class)->set('support_request_relay_source_system', 'hotline.command');
        app(SettingsService::class)->set('support_request_relay_target_systems', "support.dispatch\nsupport.ops");

        $supportRequest = app(SupportRequestCreationService::class)->create(
            $this->requestPayload($this->createSitrep()),
            $this->createCommandUser(),
        );

        Http::fake([
            'https://relay.pbb.ph/api/v1/messages' => Http::response([
                'success' => true,
                'relay_id' => '01HZSREQ000000000000000001',
                'message_id' => '01KSUPPORTREQ000000000001',
                'status' => 'queued',
                'deliveries_count' => 1,
                'deliveries' => [],
            ], 201),
        ]);
        Log::spy();

        app(SupportRequestRelaySubmissionService::class)->submit($supportRequest);

        Http::assertSent(function ($request) use ($supportRequest): bool {
            $data = $request->data();
            $payload = $request['payload'];

            return $request->url() === 'https://relay.pbb.ph/api/v1/messages'
                && $request->hasHeader('X-Relay-Key', 'test-relay-key')
                && $request->hasHeader('Connection', 'close')
                && $request['source_system'] === 'hotline.command'
                && ! array_key_exists('target_hq_hub_id', $data)
                && ! array_key_exists('target_hub_ids', $data)
                && ! array_key_exists('target_system', $data)
                && ! array_key_exists('target_systems', $data)
                && $request['targets'] === [[
                    'id' => '11',
                    'systems' => ['support.dispatch', 'support.ops'],
                ]]
                && $request['message_type'] === 'support.request'
                && $request['reference_type'] === 'support_request'
                && $request['reference_id'] === $supportRequest->local_request_id
                && $request['correlation_id'] === $supportRequest->correlation_id
                && $payload['schema_version'] === 1
                && $payload['request']['local_request_id'] === $supportRequest->local_request_id
                && $payload['request']['requested_assistance'] === 'Rescue and extraction support'
                && $payload['source']['system'] === 'hotline.command'
                && $payload['source']['relay_hub_id'] === '072217029'
                && $payload['requester']['display_name'] === 'Command User'
                && $payload['sitrep']['sequence_number'] === '0054'
                && $payload['gap']['title'] === 'Resource supply not confirmed'
                && $payload['evidence_row']['category'] === 'Rescue and Extraction'
                && $payload['incident_refs'] === [[
                    'id' => 234,
                    'public_code' => 'A000234',
                ]]
                && ! array_key_exists('summary', $payload)
                && ! array_key_exists('source_snapshot', $payload)
                && ! array_key_exists('full_sitrep', $payload);
        });

        $this->assertDatabaseHas('support_requests', [
            'id' => $supportRequest->id,
            'status' => SupportRequest::STATUS_RELAY_ACCEPTED,
            'relay_delivery_status' => SupportRequest::RELAY_ACCEPTED,
            'relay_id' => '01HZSREQ000000000000000001',
            'relay_message_id' => '01KSUPPORTREQ000000000001',
        ]);

        $this->assertDatabaseHas('support_request_histories', [
            'support_request_id' => $supportRequest->id,
            'event_type' => 'support.request.relay_accepted',
            'status' => SupportRequest::STATUS_RELAY_ACCEPTED,
            'relay_message_id' => '01KSUPPORTREQ000000000001',
        ]);
    }

    public function test_support_request_relay_submission_fails_before_post_when_no_valid_uplink_targets_exist(): void
    {
        app(SettingsService::class)->set('relay_url', 'https://relay.pbb.ph');
        app(SettingsService::class)->set('relay_token', 'test-relay-key');

        $supportRequest = app(SupportRequestCreationService::class)->create([
            ...$this->requestPayload($this->createSitrep()),
            'source_snapshot' => [
                'hub_id' => '072217029',
                'relay_hub_id' => '072217029',
                'name' => 'Guadalupe, Cebu City, Cebu',
                'uplinks' => [
                    ['hub' => ['id' => null]],
                    ['hub' => ['id' => '']],
                    ['hub' => []],
                ],
            ],
        ], $this->createCommandUser());

        Http::fake();

        app(SupportRequestRelaySubmissionService::class)->submit($supportRequest);

        Http::assertNothingSent();
        $this->assertDatabaseHas('support_requests', [
            'id' => $supportRequest->id,
            'status' => SupportRequest::STATUS_FAILED,
            'relay_delivery_status' => SupportRequest::RELAY_FAILED,
            'relay_last_error' => 'Relay target hubs are not available from hub.json uplinks.',
        ]);
    }

    public function test_passive_sitrep_records_do_not_create_support_requests(): void
    {
        $this->createSitrep();

        $this->assertDatabaseCount('support_requests', 0);
        $this->assertDatabaseCount('support_request_histories', 0);
    }

    private function createCommandUser(): User
    {
        return User::query()->create([
            'name' => 'Command User',
            'email' => 'command@example.test',
            'password' => bcrypt('password'),
            'role' => 'command',
            'status' => 'active',
        ]);
    }

    private function createSitrep(): SitrepReport
    {
        $generated = Carbon::parse('2026-06-11 08:00:00', 'Asia/Manila');

        return SitrepReport::query()->create([
            'sequence_number' => 54,
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
                        'snapshot' => $this->hubSnapshot(),
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
    private function requestPayload(SitrepReport $sitrep): array
    {
        return [
            'sitrep_report_id' => $sitrep->id,
            'sitrep_section' => 'gaps',
            'sitrep_evidence_ref' => 'gaps.resource_supply.1',
            'urgency' => 'high',
            'requested_assistance' => 'Rescue and extraction support',
            'requested_capability' => 'rescue_and_extraction',
            'quantity' => 2,
            'quantity_unit' => 'teams',
            'staging_notes' => 'Stage near Barangay Hall. Main road blocked; use alternate route.',
            'command_notes' => 'Local team capacity exceeded by active incidents.',
            'gap' => [
                'title' => 'Resource supply not confirmed',
                'category' => 'Operational constraint',
                'type' => 'open_needs',
            ],
            'evidence_row' => [
                'category' => 'Rescue and Extraction',
                'quantity' => 2,
                'resources' => 'Rescue Team',
                'location_name' => 'Guadalupe',
            ],
            'incident_refs' => [[
                'id' => 234,
                'public_code' => 'A000234',
            ]],
        ];
    }
}
