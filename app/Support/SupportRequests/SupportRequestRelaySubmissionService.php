<?php

namespace App\Support\SupportRequests;

use App\Domain\SupportRequests\Models\SupportRequest;
use App\Support\Settings\SettingsService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupportRequestRelaySubmissionService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function submit(SupportRequest $supportRequest): SupportRequest
    {
        $relayUrl = rtrim(trim((string) $this->settings->get('relay_url', 'https://relay.pbb.ph')), '/');
        $relayToken = trim((string) $this->settings->get('relay_token', ''));

        if ($relayUrl === '') {
            return $this->markFailed($supportRequest, 'Relay URL is not configured.');
        }

        if ($relayToken === '') {
            return $this->markFailed($supportRequest, 'Relay token is not configured.');
        }

        $supportRequest->forceFill([
            'relay_attempt_count' => $supportRequest->relay_attempt_count + 1,
            'relay_last_attempted_at' => now(),
        ])->save();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'Connection' => 'close',
                    'X-Relay-Key' => $relayToken,
                ])
                ->connectTimeout(5)
                ->timeout(30)
                ->post($relayUrl.'/api/v1/messages', $this->envelope($supportRequest));

            if (! $response->successful()) {
                $message = sprintf(
                    'Relay rejected Support Request handoff with HTTP %d: %s',
                    $response->status(),
                    Str::limit($response->body(), 500),
                );

                Log::warning('Support Request Relay submission failed.', array_merge(
                    $this->logContext($supportRequest),
                    [
                        'reason' => 'relay_rejected',
                        'http_status' => $response->status(),
                        'error' => $message,
                    ],
                ));

                return $this->markFailed($supportRequest, $message);
            }

            $payload = $response->json();

            $supportRequest->forceFill([
                'status' => SupportRequest::STATUS_RELAY_ACCEPTED,
                'relay_delivery_status' => SupportRequest::RELAY_ACCEPTED,
                'relay_id' => is_string($payload['relay_id'] ?? null) ? $payload['relay_id'] : null,
                'relay_message_id' => is_scalar($payload['message_id'] ?? null) ? (string) $payload['message_id'] : null,
                'relay_deliveries_count' => is_numeric($payload['deliveries_count'] ?? null) ? (int) $payload['deliveries_count'] : null,
                'relay_last_error' => null,
                'relay_submitted_at' => now(),
                'relay_response_json' => is_array($payload) ? $payload : null,
            ])->save();

            $supportRequest->histories()->create([
                'event_type' => 'support.request.relay_accepted',
                'status' => SupportRequest::STATUS_RELAY_ACCEPTED,
                'relay_message_id' => $supportRequest->relay_message_id,
                'source_system' => $supportRequest->source_system,
                'message' => 'Relay accepted the Support Request for delivery.',
                'payload_json' => is_array($payload) ? $payload : null,
                'occurred_at' => now(),
            ]);

            Log::info('Support Request Relay submission accepted.', array_merge(
                $this->logContext($supportRequest),
                [
                    'relay_id' => $supportRequest->relay_id,
                    'relay_message_id' => $supportRequest->relay_message_id,
                    'deliveries_count' => $supportRequest->relay_deliveries_count,
                ],
            ));

            return $supportRequest;
        } catch (\InvalidArgumentException $exception) {
            Log::warning('Support Request Relay submission failed.', array_merge(
                $this->logContext($supportRequest),
                [
                    'reason' => 'invalid_envelope',
                    'error' => $exception->getMessage(),
                ],
            ));

            return $this->markFailed($supportRequest, $exception->getMessage());
        } catch (RequestException $exception) {
            Log::warning('Support Request Relay submission failed.', array_merge(
                $this->logContext($supportRequest),
                [
                    'reason' => 'request_exception',
                    'error' => $exception->getMessage(),
                ],
            ));

            return $this->markFailed($supportRequest, $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('Support Request Relay submission failed unexpectedly.', array_merge(
                $this->logContext($supportRequest),
                [
                    'reason' => 'unexpected_exception',
                    'error' => $exception->getMessage(),
                ],
            ));

            return $this->markFailed($supportRequest, $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(SupportRequest $supportRequest): array
    {
        return [
            'source_system' => $this->sourceSystem(),
            'targets' => $this->targets($supportRequest),
            'message_type' => 'support.request',
            'payload_format' => 'json',
            'payload_version' => '1.0',
            'reference_type' => 'support_request',
            'reference_id' => $supportRequest->local_request_id,
            'correlation_id' => $supportRequest->correlation_id,
            'priority' => $this->priority($supportRequest),
            'attachments_count' => 0,
            'occurred_at' => $supportRequest->requested_at?->toIso8601String(),
            'payload' => $this->payload($supportRequest),
        ];
    }

    private function sourceSystem(): string
    {
        $sourceSystem = trim((string) $this->settings->get('support_request_relay_source_system', 'hotline.command'));

        return $sourceSystem !== '' ? $sourceSystem : 'hotline.command';
    }

    /**
     * @return array<int, string>
     */
    private function targetSystems(): array
    {
        $configured = (string) $this->settings->get('support_request_relay_target_systems', 'support.dispatch');
        $targets = preg_split('/[\s,]+/', $configured) ?: [];
        $targets = array_values(array_unique(array_filter(array_map(
            fn (string $target): string => trim($target),
            $targets,
        ), fn (string $target): bool => $target !== '')));

        return $targets !== [] ? $targets : ['support.dispatch'];
    }

    /**
     * @return array<int, array{id: string, systems: array<int, string>}>
     */
    private function targets(SupportRequest $supportRequest): array
    {
        $snapshot = is_array($supportRequest->source_snapshot_json) ? $supportRequest->source_snapshot_json : [];
        $uplinks = is_array($snapshot['uplinks'] ?? null) ? $snapshot['uplinks'] : [];
        $systems = $this->targetSystems();
        $targets = [];

        foreach ($uplinks as $uplink) {
            if (! is_array($uplink) || ! is_array($uplink['hub'] ?? null)) {
                continue;
            }

            $id = $uplink['hub']['id'] ?? null;

            if (! is_scalar($id)) {
                continue;
            }

            $id = trim((string) $id);

            if ($id === '' || isset($targets[$id])) {
                continue;
            }

            $targets[$id] = [
                'id' => $id,
                'systems' => $systems,
            ];
        }

        if ($targets === []) {
            throw new \InvalidArgumentException('Relay target hubs are not available from hub.json uplinks.');
        }

        return array_values($targets);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SupportRequest $supportRequest): array
    {
        return [
            'schema_version' => 1,
            'request' => [
                'local_request_id' => $supportRequest->local_request_id,
                'correlation_id' => $supportRequest->correlation_id,
                'status' => SupportRequest::STATUS_REQUESTED,
                'urgency' => $supportRequest->urgency,
                'requested_assistance' => $supportRequest->requested_assistance,
                'requested_capability' => $supportRequest->requested_capability,
                'quantity' => $supportRequest->quantity,
                'quantity_unit' => $supportRequest->quantity_unit,
                'staging_notes' => $supportRequest->staging_notes,
                'command_notes' => $supportRequest->command_notes,
                'requested_at' => $supportRequest->requested_at?->toIso8601String(),
            ],
            'source' => [
                'system' => $supportRequest->source_system,
                'hub_id' => $supportRequest->source_hub_id,
                'relay_hub_id' => $supportRequest->source_relay_hub_id,
                'hub_name' => $supportRequest->source_hub_name,
            ],
            'requester' => [
                'user_id' => $supportRequest->requester_user_id ? (string) $supportRequest->requester_user_id : null,
                'display_name' => $supportRequest->requester_name,
                'role' => $supportRequest->requester_role,
            ],
            'sitrep' => [
                'id' => $supportRequest->sitrep_report_id,
                'sequence_number' => $supportRequest->sitrep_sequence_number !== null
                    ? sprintf('%04d', $supportRequest->sitrep_sequence_number)
                    : null,
                'generated_at' => $supportRequest->sitrep_generated_at?->toIso8601String(),
                'evidence_ref' => $supportRequest->sitrep_evidence_ref,
                'section' => $supportRequest->sitrep_section,
            ],
            'gap' => $supportRequest->gap_json,
            'resource' => $this->resource($supportRequest),
            'evidence_row' => $supportRequest->evidence_row_json,
            'incident_refs' => $supportRequest->incident_refs_json ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resource(SupportRequest $supportRequest): ?array
    {
        $row = is_array($supportRequest->evidence_row_json) ? $supportRequest->evidence_row_json : [];
        $resourceTypeId = (int) ($row['resource_type_id'] ?? 0);

        if ($resourceTypeId <= 0) {
            return null;
        }

        return [
            'resource_type_id' => $resourceTypeId,
            'resource_type_name' => is_scalar($row['resource_type_name'] ?? null) ? (string) $row['resource_type_name'] : null,
            'resource_type_category_id' => is_numeric($row['resource_type_category_id'] ?? null) ? (int) $row['resource_type_category_id'] : null,
            'resource_type_category_name' => is_scalar($row['resource_type_category_name'] ?? null) ? (string) $row['resource_type_category_name'] : null,
            'quantity' => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : null,
            'unit_label' => is_scalar($row['unit_label'] ?? null) ? (string) $row['unit_label'] : null,
            'incident_ids' => array_values(array_filter(
                array_map(
                    fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
                    is_array($row['incident_ids'] ?? null) ? $row['incident_ids'] : []
                ),
                fn (?int $id): bool => $id !== null
            )),
        ];
    }

    private function priority(SupportRequest $supportRequest): string
    {
        return match (strtolower((string) $supportRequest->urgency)) {
            'critical', 'urgent', 'emergency' => 'urgent',
            'high' => 'high',
            default => 'normal',
        };
    }

    private function markFailed(SupportRequest $supportRequest, string $message): SupportRequest
    {
        $supportRequest->forceFill([
            'status' => SupportRequest::STATUS_FAILED,
            'relay_delivery_status' => SupportRequest::RELAY_FAILED,
            'relay_last_error' => Str::limit($message, 2000),
            'relay_last_attempted_at' => now(),
        ])->save();

        $supportRequest->histories()->create([
            'event_type' => 'support.request.relay_failed',
            'status' => SupportRequest::STATUS_FAILED,
            'source_system' => $supportRequest->source_system,
            'message' => Str::limit($message, 2000),
            'payload_json' => ['error' => Str::limit($message, 2000)],
            'occurred_at' => now(),
        ]);

        Log::warning('Support Request Relay delivery marked failed.', array_merge(
            $this->logContext($supportRequest),
            ['error' => Str::limit($message, 2000)],
        ));

        return $supportRequest;
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(SupportRequest $supportRequest): array
    {
        return [
            'support_request_id' => $supportRequest->id,
            'local_request_id' => $supportRequest->local_request_id,
            'correlation_id' => $supportRequest->correlation_id,
            'attempt_count' => $supportRequest->relay_attempt_count,
            'status' => $supportRequest->status,
            'relay_delivery_status' => $supportRequest->relay_delivery_status,
            'source_system' => $this->sourceSystem(),
            'relay_target_systems_setting' => $this->targetSystems(),
            'relay_target_ids' => $this->targetHubIds($supportRequest),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function targetHubIds(SupportRequest $supportRequest): array
    {
        try {
            return array_column($this->targets($supportRequest), 'id');
        } catch (\InvalidArgumentException) {
            return [];
        }
    }
}
