<?php

namespace Tests\Feature\Internal;

use App\Domain\SupportRequests\Models\SupportRequest;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SupportRequestUpdateIngressTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_support_request_update_requires_relay_handler_token(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope())
            ->assertUnauthorized()
            ->assertJsonPath('ok', false);
    }

    public function test_inbound_support_request_update_rejects_initial_support_request_message(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');
        $supportRequest = $this->supportRequest();

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
            'message_type' => 'support.request',
            'payload' => [
                'schema_version' => 1,
                'local_request_id' => $supportRequest->local_request_id,
                'correlation_id' => $supportRequest->correlation_id,
                'status' => SupportRequest::STATUS_RECEIVED,
                'updated_at' => '2026-06-11T09:00:00+08:00',
                'update_id' => 'upd_initial',
            ],
        ]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseMissing('support_request_histories', [
            'update_id' => 'upd_initial',
        ]);
    }

    public function test_inbound_support_request_lifecycle_statuses_update_request_and_append_history(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');
        $supportRequest = $this->supportRequest();
        $statuses = [
            SupportRequest::STATUS_RECEIVED,
            SupportRequest::STATUS_ACCEPTED,
            SupportRequest::STATUS_REJECTED,
            SupportRequest::STATUS_ASSIGNED,
            SupportRequest::STATUS_EN_ROUTE,
            SupportRequest::STATUS_FULFILLED,
            SupportRequest::STATUS_CLOSED,
        ];

        foreach ($statuses as $index => $status) {
            $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
                'message_type' => 'support.request.'.$status,
                'message_id' => 'relay_msg_'.$status,
                'payload' => [
                    'schema_version' => 1,
                    'local_request_id' => $supportRequest->local_request_id,
                    'correlation_id' => $supportRequest->correlation_id,
                    'support_request_id' => 'sup_01kt_city_support',
                    'status' => $status,
                    'updated_at' => Carbon::parse('2026-06-11T09:00:00+08:00')->addMinutes($index)->toIso8601String(),
                    'update_id' => 'upd_'.$status,
                    'updated_by' => [
                        'system' => 'support.dispatch',
                        'display_name' => 'Support Coordinator',
                    ],
                    'message' => 'Support update: '.$status,
                ],
            ]), $this->headers())
                ->assertStatus(202)
                ->assertJsonPath('ok', true)
                ->assertJsonPath('status', 'accepted');
        }

        $this->assertDatabaseHas('support_requests', [
            'id' => $supportRequest->id,
            'status' => SupportRequest::STATUS_CLOSED,
            'support_request_id' => 'sup_01kt_city_support',
        ]);

        foreach ($statuses as $status) {
            $this->assertDatabaseHas('support_request_histories', [
                'support_request_id' => $supportRequest->id,
                'event_type' => 'support.request.'.$status,
                'status' => $status,
                'relay_message_id' => 'relay_msg_'.$status,
                'update_id' => 'upd_'.$status,
                'support_request_external_id' => 'sup_01kt_city_support',
                'source_system' => 'support.dispatch',
                'actor_name' => 'Support Coordinator',
                'message' => 'Support update: '.$status,
            ]);
        }
    }

    public function test_inbound_support_request_update_accepts_under_review_status(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');
        $supportRequest = $this->supportRequest();

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
            'message_type' => 'support.request.under_review',
            'message_id' => 'relay_msg_under_review',
            'payload' => [
                'schema_version' => 1,
                'hotline_request_id' => $supportRequest->local_request_id,
                'status' => SupportRequest::STATUS_UNDER_REVIEW,
                'updated_at' => '2026-06-11T09:05:00+08:00',
                'update_id' => 'upd_under_review',
            ],
        ]), $this->headers())
            ->assertStatus(202)
            ->assertJsonPath('support_request.status', SupportRequest::STATUS_UNDER_REVIEW);

        $this->assertDatabaseHas('support_requests', [
            'id' => $supportRequest->id,
            'status' => SupportRequest::STATUS_UNDER_REVIEW,
        ]);
    }

    public function test_inbound_support_request_update_is_idempotent_by_relay_message_or_update_id(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');
        $supportRequest = $this->supportRequest();
        $basePayload = [
            'message_type' => 'support.request.assigned',
            'message_id' => 'relay_msg_duplicate',
            'payload' => [
                'schema_version' => 1,
                'local_request_id' => $supportRequest->local_request_id,
                'correlation_id' => $supportRequest->correlation_id,
                'support_request_id' => 'sup_duplicate',
                'status' => SupportRequest::STATUS_ASSIGNED,
                'updated_at' => '2026-06-11T09:15:00+08:00',
                'update_id' => 'upd_duplicate',
                'message' => 'Team assigned.',
            ],
        ];

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope($basePayload), $this->headers())
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted');

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
            ...$basePayload,
            'payload' => [
                ...$basePayload['payload'],
                'update_id' => 'upd_different',
                'message' => 'Duplicate Relay message.',
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
            ...$basePayload,
            'message_id' => 'relay_msg_different',
            'payload' => [
                ...$basePayload['payload'],
                'message' => 'Duplicate update id.',
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('support_request_histories', 1);
        $this->assertDatabaseHas('support_requests', [
            'id' => $supportRequest->id,
            'status' => SupportRequest::STATUS_ASSIGNED,
        ]);
    }

    public function test_inbound_support_request_update_can_find_request_by_correlation_id(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');
        $supportRequest = $this->supportRequest();

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
            'message_type' => 'support.request.accepted',
            'message_id' => 'relay_msg_correlation',
            'payload' => [
                'schema_version' => 1,
                'correlation_id' => $supportRequest->correlation_id,
                'status' => SupportRequest::STATUS_ACCEPTED,
                'updated_at' => '2026-06-11T09:20:00+08:00',
                'update_id' => 'upd_correlation',
            ],
        ]), $this->headers())
            ->assertStatus(202)
            ->assertJsonPath('support_request.local_request_id', $supportRequest->local_request_id);
    }

    public function test_inbound_support_request_update_for_unknown_request_is_explicit(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
            'message_type' => 'support.request.assigned',
            'message_id' => 'relay_msg_unknown',
            'payload' => [
                'schema_version' => 1,
                'local_request_id' => 'srq_unknown',
                'correlation_id' => 'srq_unknown',
                'status' => SupportRequest::STATUS_ASSIGNED,
                'updated_at' => '2026-06-11T09:30:00+08:00',
                'update_id' => 'upd_unknown',
            ],
        ]), $this->headers())
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'unknown_request');

        $this->assertDatabaseCount('support_request_histories', 0);
    }

    public function test_inbound_support_request_update_rejects_status_that_does_not_match_message_type(): void
    {
        app(SettingsService::class)->set('support_request_relay_handler_token', 'handler-secret');
        $supportRequest = $this->supportRequest();

        $this->postJson('/api/internal/relay/support-request-updates', $this->envelope([
            'message_type' => 'support.request.assigned',
            'message_id' => 'relay_msg_mismatch',
            'payload' => [
                'schema_version' => 1,
                'local_request_id' => $supportRequest->local_request_id,
                'status' => SupportRequest::STATUS_ACCEPTED,
                'updated_at' => '2026-06-11T09:35:00+08:00',
                'update_id' => 'upd_mismatch',
            ],
        ]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('support_request_histories', 0);
    }

    private function supportRequest(): SupportRequest
    {
        return SupportRequest::query()->create([
            'local_request_id' => 'srq_01ktlocalrequest000000001',
            'correlation_id' => 'srq_01ktcorrelation00000001',
            'status' => SupportRequest::STATUS_RELAY_ACCEPTED,
            'relay_delivery_status' => SupportRequest::RELAY_ACCEPTED,
            'urgency' => 'urgent',
            'requested_assistance' => 'Rescue and extraction support',
            'requested_capability' => 'rescue_and_extraction',
            'source_system' => 'hotline.command',
            'requested_at' => '2026-06-11T08:30:00+08:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides = []): array
    {
        return [
            'message_type' => 'support.request.received',
            'message_id' => 'relay_msg_01',
            'source_system' => 'support.dispatch',
            'payload' => [
                'schema_version' => 1,
                'local_request_id' => 'srq_01ktlocalrequest000000001',
                'correlation_id' => 'srq_01ktcorrelation00000001',
                'support_request_id' => 'sup_01ktcityrequest00000001',
                'status' => SupportRequest::STATUS_RECEIVED,
                'status_label' => 'Received',
                'updated_at' => '2026-06-11T09:00:00+08:00',
                'update_id' => 'upd_01',
                'updated_by' => [
                    'system' => 'support.dispatch',
                    'display_name' => 'Support Coordinator',
                ],
                'message' => 'Support received the request.',
            ],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Relay-Key' => 'handler-secret',
        ];
    }
}
