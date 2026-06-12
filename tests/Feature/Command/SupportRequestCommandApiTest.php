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
use Illuminate\Support\Facades\DB;
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
                && $request['payload']['resource']['resource_type_id'] > 0
                && $request['payload']['resource']['resource_type_name'] === 'Rescue Team'
                && $request['payload']['resource']['resource_type_category_id'] > 0
                && $request['payload']['resource']['resource_type_category_name'] === 'Rescue and Extraction'
                && $request['payload']['resource']['quantity'] === 2
                && $request['payload']['resource']['unit_label'] === 'teams'
                && $request['payload']['resource']['incident_ids'] === [234]
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

    public function test_command_support_request_rejects_data_confidence_population_gap(): void
    {
        $this->configureRelay();
        $command = User::factory()->create(['role' => UserRole::Command]);
        $sitrep = $this->createSitrep();

        Http::fake();

        $this->actingAs($command)
            ->postJson('/api/command/support-requests', [
                ...$this->payload($sitrep),
                'requested_assistance' => 'Validate population figures',
                'requested_capability' => 'Population verification',
                'gap' => [
                    'title' => 'Population figures require verification',
                    'category' => 'Data Confidence',
                    'type' => 'population_verification',
                ],
                'evidence_row' => [
                    'population_signal' => 'Patient or injured person',
                    'reports' => 14,
                    'people' => 14,
                    'notes' => 'Population fields may overlap.',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('support_context');

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_requests', 0);
        $this->assertDatabaseCount('support_request_histories', 0);
    }

    public function test_command_support_request_rejects_route_only_gap_evidence(): void
    {
        $this->configureRelay();
        $command = User::factory()->create(['role' => UserRole::Command]);
        $sitrep = $this->createSitrep();

        Http::fake();

        $this->actingAs($command)
            ->postJson('/api/command/support-requests', [
                ...$this->payload($sitrep),
                'requested_assistance' => 'Clear road access',
                'requested_capability' => 'Road clearing',
                'gap' => [
                    'title' => 'Road/access constraints may affect field movement',
                    'category' => 'Operational constraint',
                    'type' => 'road_access',
                ],
                'evidence_row' => [
                    'route_location' => 'Main Road',
                    'status' => 'limited',
                    'obstruction_type' => 'floodwater',
                    'cleared' => false,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('support_context');

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_requests', 0);
        $this->assertDatabaseCount('support_request_histories', 0);
    }

    public function test_command_support_request_rejects_route_gap_with_crafted_resource_row(): void
    {
        $this->configureRelay();
        $command = User::factory()->create(['role' => UserRole::Command]);
        $sitrep = $this->createSitrep();
        $payload = $this->payload($sitrep);
        $payload['gap'] = [
            'title' => 'Road/access constraints may affect field movement',
            'category' => 'Operational constraint',
            'type' => 'road_access',
        ];

        Http::fake();

        $this->actingAs($command)
            ->postJson('/api/command/support-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('support_context');

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_requests', 0);
        $this->assertDatabaseCount('support_request_histories', 0);
    }

    public function test_command_support_request_rejects_population_gap_with_crafted_resource_row(): void
    {
        $this->configureRelay();
        $command = User::factory()->create(['role' => UserRole::Command]);
        $sitrep = $this->createSitrep();
        $payload = $this->payload($sitrep);
        $payload['gap'] = [
            'title' => 'Population figures require verification',
            'category' => 'Data Confidence',
            'type' => 'population_verification',
        ];

        Http::fake();

        $this->actingAs($command)
            ->postJson('/api/command/support-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('support_context');

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_requests', 0);
        $this->assertDatabaseCount('support_request_histories', 0);
    }

    public function test_command_support_request_rejects_free_text_resource_context_without_resource_type(): void
    {
        $this->configureRelay();
        $command = User::factory()->create(['role' => UserRole::Command]);
        $sitrep = $this->createSitrep();

        Http::fake();

        $this->actingAs($command)
            ->postJson('/api/command/support-requests', [
                ...$this->payload($sitrep),
                'evidence_row' => [
                    'category' => 'Rescue and Extraction',
                    'resource' => 'Rescue Team',
                    'quantity' => 2,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('support_context');

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_requests', 0);
        $this->assertDatabaseCount('support_request_histories', 0);
    }

    public function test_command_support_request_rejects_counting_and_resolved_context(): void
    {
        $this->configureRelay();
        $command = User::factory()->create(['role' => UserRole::Command]);
        $sitrep = $this->createSitrep();

        Http::fake();

        $this->actingAs($command)
            ->postJson('/api/command/support-requests', [
                ...$this->payload($sitrep),
                'requested_assistance' => 'Review resolved incident accounting',
                'requested_capability' => 'Counting review',
                'gap' => [
                    'title' => 'Closed and discarded reports are not current pressure',
                    'category' => 'Counting Rule',
                    'type' => 'counting_scope',
                ],
                'evidence_row' => [
                    'location' => 'Guadalupe',
                    'evidence' => '5 resolved reports were treated as addressed history; 2 discarded reports were excluded.',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('support_context');

        Http::assertNothingSent();
        $this->assertDatabaseCount('support_requests', 0);
        $this->assertDatabaseCount('support_request_histories', 0);
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
        $categoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Rescue and Extraction',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $categoryId,
            'name' => 'Rescue Team',
            'unit_label' => 'teams',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
                'kind' => 'resource_need',
                'resource_type_id' => $resourceTypeId,
                'resource_type_name' => 'Rescue Team',
                'resource_type_category_id' => $categoryId,
                'resource_type_category_name' => 'Rescue and Extraction',
                'category' => 'Rescue and Extraction',
                'resource' => 'Rescue Team',
                'quantity' => 2,
                'unit_label' => 'teams',
                'incident_ids' => [234],
                'routes' => [[
                    'route_location' => 'Main Road',
                    'status' => 'limited',
                    'obstruction_type' => 'floodwater',
                    'cleared' => false,
                    'incident_ids' => [234],
                ]],
                'population' => [[
                    'signal' => 'Patient or injured person',
                    'reports' => 1,
                    'people' => 1,
                    'notes' => ['stable'],
                    'incident_ids' => [234],
                ]],
            ],
            'incident_refs' => [[
                'id' => 234,
                'public_code' => 'A000234',
            ]],
        ];
    }
}
