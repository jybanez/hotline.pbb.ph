<?php

namespace App\Support\IncidentRelay;

use App\Domain\IncidentRelay\Models\IncidentRelayDelivery;
use App\Domain\IncidentRelay\Models\IncidentRelayOutbox;
use App\Domain\Incidents\Models\Incident;
use App\Support\Settings\SettingsService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IncidentRelaySubmissionService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly IncidentRelaySerializer $serializer,
        private readonly IncidentRelayHubContext $hubContext,
    ) {
    }

    public function submit(IncidentRelayOutbox $outbox): IncidentRelayDelivery
    {
        $outbox->loadMissing('incident');
        $incident = $outbox->incident;

        if (! $incident instanceof Incident) {
            $delivery = $this->failedDeliveryFromOutbox($outbox, 'Incident is missing.');
            $this->markOutboxFailed($outbox, $delivery->last_error ?? 'Incident is missing.');

            return $delivery;
        }

        if (! (bool) $this->settings->get('incident_relay_enabled', false)) {
            $delivery = $this->makeDelivery($incident, $this->serializer->serialize($incident));
            $delivery->forceFill([
                'status' => IncidentRelayDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => 'Incident Relay is disabled.',
            ])->save();

            $this->markOutboxFailed($outbox, 'Incident Relay is disabled.');

            return $delivery;
        }

        $relayUrl = rtrim(trim((string) $this->settings->get('relay_url', 'https://relay.pbb.ph')), '/');
        $relayToken = trim((string) $this->settings->get('relay_token', ''));

        if ($relayUrl === '') {
            $delivery = $this->makeDelivery($incident, $this->serializer->serialize($incident));
            $delivery->forceFill([
                'status' => IncidentRelayDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => 'Relay URL is not configured.',
            ])->save();
            $this->markOutboxFailed($outbox, 'Relay URL is not configured.');

            return $delivery;
        }

        if ($relayToken === '') {
            $delivery = $this->makeDelivery($incident, $this->serializer->serialize($incident));
            $delivery->forceFill([
                'status' => IncidentRelayDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => 'Relay token is not configured.',
            ])->save();
            $this->markOutboxFailed($outbox, 'Relay token is not configured.');

            return $delivery;
        }

        $payload = $this->serializer->serialize($incident);
        $payloadHash = $this->payloadHash($payload);
        $sentDelivery = $this->alreadySentDelivery($payload, $payloadHash);

        if ($sentDelivery instanceof IncidentRelayDelivery) {
            $outbox->delete();

            Log::info('Incident Relay outbox cleared because the exported payload was already sent.', [
                'incident_id' => $incident->id,
                'incident_relay_delivery_id' => $sentDelivery->id,
                'stable_incident_key' => $sentDelivery->stable_incident_key,
                'idempotency_key' => $sentDelivery->idempotency_key,
            ]);

            return $sentDelivery;
        }

        $delivery = $this->makeDelivery($incident, $payload);

        $outbox->forceFill([
            'status' => IncidentRelayOutbox::STATUS_PROCESSING,
            'attempt_count' => $outbox->attempt_count + 1,
            'last_attempted_at' => now(),
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
                ->post($relayUrl.'/api/v1/messages', $this->envelope($payload));

            if (! $response->successful()) {
                $message = sprintf(
                    'Relay rejected Incident handoff with HTTP %d: %s',
                    $response->status(),
                    Str::limit($response->body(), 500),
                );

                Log::warning('Incident Relay submission failed.', array_merge(
                    $this->logContext($delivery, $incident),
                    [
                        'reason' => 'relay_rejected',
                        'http_status' => $response->status(),
                        'error' => $message,
                    ],
                ));

                $delivery->forceFill([
                    'status' => IncidentRelayDelivery::STATUS_FAILED,
                    'failed_at' => now(),
                    'last_error' => Str::limit($message, 2000),
                ])->save();
                $this->markOutboxFailed($outbox, $message);

                return $delivery;
            }

            $responsePayload = $response->json();

            $delivery->forceFill([
                'status' => IncidentRelayDelivery::STATUS_SENT,
                'relay_id' => is_string($responsePayload['relay_id'] ?? null) ? $responsePayload['relay_id'] : null,
                'relay_message_id' => is_scalar($responsePayload['message_id'] ?? null) ? (string) $responsePayload['message_id'] : null,
                'deliveries_count' => is_numeric($responsePayload['deliveries_count'] ?? null) ? (int) $responsePayload['deliveries_count'] : null,
                'sent_at' => now(),
                'last_error' => null,
                'response_json' => is_array($responsePayload) ? $responsePayload : null,
            ])->save();

            $outbox->delete();

            Log::info('Incident Relay submission accepted.', array_merge(
                $this->logContext($delivery, $incident),
                [
                    'relay_id' => $delivery->relay_id,
                    'relay_message_id' => $delivery->relay_message_id,
                    'deliveries_count' => $delivery->deliveries_count,
                ],
            ));

            return $delivery;
        } catch (\InvalidArgumentException $exception) {
            $message = $exception->getMessage();
        } catch (RequestException $exception) {
            $message = $exception->getMessage();
        } catch (\Throwable $exception) {
            report($exception);
            $message = $exception->getMessage();
        }

        Log::warning('Incident Relay submission failed.', array_merge(
            $this->logContext($delivery, $incident),
            ['error' => $message],
        ));

        $delivery->forceFill([
            'status' => IncidentRelayDelivery::STATUS_FAILED,
            'failed_at' => now(),
            'last_error' => Str::limit($message, 2000),
        ])->save();
        $this->markOutboxFailed($outbox, $message);

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function envelope(array $payload): array
    {
        $snapshot = $this->hubContext->snapshot();

        return [
            'source_system' => $this->serializer->sourceSystem(),
            'targets' => $this->hubContext->targets($snapshot),
            'message_type' => IncidentRelaySerializer::MESSAGE_TYPE,
            'payload_format' => 'json',
            'payload_version' => '1.0',
            'reference_type' => 'incident',
            'reference_id' => (string) ($payload['source']['incident_id'] ?? ''),
            'correlation_id' => (string) ($payload['stable_incident_key'] ?? ''),
            'idempotency_key' => (string) ($payload['message_idempotency_key'] ?? ''),
            'priority' => $this->priority($payload),
            'attachments_count' => count($payload['incident']['media_refs'] ?? []),
            'occurred_at' => $payload['incident']['timestamps']['updated_at'] ?? null,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeDelivery(Incident $incident, array $payload): IncidentRelayDelivery
    {
        $idempotencyKey = (string) ($payload['message_idempotency_key'] ?? '');
        $existing = IncidentRelayDelivery::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof IncidentRelayDelivery) {
            return $existing;
        }

        return IncidentRelayDelivery::query()->create([
            'incident_id' => $incident->id,
            'message_type' => IncidentRelaySerializer::MESSAGE_TYPE,
            'status' => IncidentRelayDelivery::STATUS_PENDING,
            'stable_incident_key' => (string) ($payload['stable_incident_key'] ?? ''),
            'revision' => (string) ($payload['revision'] ?? ''),
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $this->payloadHash($payload),
            'payload_summary_json' => [
                'incident_id' => $incident->id,
                'incident_ref' => $payload['source']['incident_ref'] ?? null,
                'status' => $payload['incident']['status'] ?? null,
                'media_refs_count' => count($payload['incident']['media_refs'] ?? []),
            ],
            'attempted_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function alreadySentDelivery(array $payload, string $payloadHash): ?IncidentRelayDelivery
    {
        $idempotencyKey = (string) ($payload['message_idempotency_key'] ?? '');

        return IncidentRelayDelivery::query()
            ->where('status', IncidentRelayDelivery::STATUS_SENT)
            ->where(function ($query) use ($idempotencyKey, $payloadHash): void {
                $query
                    ->where('idempotency_key', $idempotencyKey)
                    ->orWhere('payload_hash', $payloadHash);
            })
            ->latest('sent_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function failedDeliveryFromOutbox(IncidentRelayOutbox $outbox, string $message): IncidentRelayDelivery
    {
        return IncidentRelayDelivery::query()->create([
            'incident_id' => $outbox->incident_id,
            'message_type' => $outbox->message_type,
            'status' => IncidentRelayDelivery::STATUS_FAILED,
            'stable_incident_key' => 'missing-incident:'.$outbox->incident_id,
            'revision' => null,
            'idempotency_key' => 'missing-incident:'.$outbox->incident_id.':'.now()->timestamp,
            'payload_hash' => hash('sha256', (string) $outbox->incident_id),
            'failed_at' => now(),
            'last_error' => Str::limit($message, 2000),
        ]);
    }

    private function markOutboxFailed(IncidentRelayOutbox $outbox, string $message): void
    {
        $outbox->forceFill([
            'status' => IncidentRelayOutbox::STATUS_FAILED,
            'last_error' => Str::limit($message, 2000),
            'last_attempted_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function priority(array $payload): string
    {
        return match ($payload['incident']['alert_level'] ?? null) {
            'Critical' => 'urgent',
            'Elevated' => 'high',
            default => 'normal',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(IncidentRelayDelivery $delivery, Incident $incident): array
    {
        return [
            'incident_id' => $incident->id,
            'incident_relay_delivery_id' => $delivery->id,
            'status' => $delivery->status,
            'stable_incident_key' => $delivery->stable_incident_key,
            'idempotency_key' => $delivery->idempotency_key,
        ];
    }
}
