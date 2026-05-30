<?php

namespace App\Support\Sitreps;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Support\Settings\SettingsService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SitrepRelaySubmissionService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SitrepExportPayloadBuilder $payloadBuilder,
    ) {
    }

    public function submit(SitrepRelayDelivery $delivery): SitrepRelayDelivery
    {
        $delivery->loadMissing('sitrepReport');
        $sitrep = $delivery->sitrepReport;

        if (! $sitrep instanceof SitrepReport) {
            return $this->markFailed($delivery, 'SITREP report is missing.');
        }

        if (! $this->isCurrentSitrep($sitrep)) {
            Log::info('SITREP Relay submission skipped because report is superseded.', $this->logContext($delivery, $sitrep));

            return $delivery;
        }

        $relayUrl = rtrim(trim((string) $this->settings->get('relay_url', 'https://relay.pbb.ph')), '/');
        $relayToken = trim((string) $this->settings->get('relay_token', ''));

        if ($relayUrl === '') {
            return $this->markFailed($delivery, 'Relay URL is not configured.');
        }

        if ($relayToken === '') {
            return $this->markFailed($delivery, 'Relay token is not configured.');
        }

        $delivery->forceFill([
            'attempt_count' => $delivery->attempt_count + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders([
                    'Connection' => 'close',
                    'X-Relay-Key' => $relayToken,
                ])
                ->timeout(10)
                ->post($relayUrl.'/api/v1/messages', $this->envelope($sitrep));

            if (! $response->successful()) {
                $message = sprintf(
                    'Relay rejected SITREP handoff with HTTP %d: %s',
                    $response->status(),
                    Str::limit($response->body(), 500),
                );

                Log::warning('SITREP Relay submission failed.', array_merge(
                    $this->logContext($delivery, $sitrep),
                    [
                        'reason' => 'relay_rejected',
                        'http_status' => $response->status(),
                        'error' => $message,
                    ],
                ));

                return $this->markFailed($delivery, $message);
            }

            $payload = $response->json();

            $delivery->forceFill([
                'status' => SitrepRelayDelivery::STATUS_SENT,
                'relay_id' => is_string($payload['relay_id'] ?? null) ? $payload['relay_id'] : null,
                'relay_message_id' => is_numeric($payload['message_id'] ?? null) ? (int) $payload['message_id'] : null,
                'deliveries_count' => is_numeric($payload['deliveries_count'] ?? null) ? (int) $payload['deliveries_count'] : null,
                'last_error' => null,
                'submitted_at' => now(),
                'response_json' => is_array($payload) ? $payload : null,
            ])->save();

            Log::info('SITREP Relay submission accepted.', array_merge(
                $this->logContext($delivery, $sitrep),
                [
                    'relay_id' => $delivery->relay_id,
                    'relay_message_id' => $delivery->relay_message_id,
                    'deliveries_count' => $delivery->deliveries_count,
                ],
            ));

            return $delivery;
        } catch (RequestException $exception) {
            Log::warning('SITREP Relay submission failed.', array_merge(
                $this->logContext($delivery, $sitrep),
                [
                    'reason' => 'request_exception',
                    'error' => $exception->getMessage(),
                ],
            ));

            return $this->markFailed($delivery, $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('SITREP Relay submission failed unexpectedly.', array_merge(
                $this->logContext($delivery, $sitrep),
                [
                    'reason' => 'unexpected_exception',
                    'error' => $exception->getMessage(),
                ],
            ));

            return $this->markFailed($delivery, $exception->getMessage());
        }
    }

    public function isCurrentSitrep(SitrepReport $sitrep): bool
    {
        // Relay carries only the latest SITREP state. Older unsent reports are
        // intentionally superseded by newer SITREPs and remain archived locally.
        $latestId = SitrepReport::query()
            ->latest('generated_at')
            ->latest('id')
            ->value('id');

        return (int) $latestId === (int) $sitrep->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(SitrepReport $sitrep): array
    {
        return [
            'source_system' => $this->sourceSystem(),
            'target_systems' => $this->targetSystems(),
            'message_type' => 'sitrep.record',
            'payload_format' => 'json',
            'payload_version' => '1.0',
            'reference_type' => 'sitrep_report',
            'reference_id' => (string) $sitrep->id,
            'correlation_id' => sprintf('sitrep-%s-%s', $sitrep->id, $sitrep->sequence_number),
            'priority' => $this->priority($sitrep),
            'attachments_count' => 0,
            'occurred_at' => $sitrep->generated_at?->toIso8601String(),
            'payload' => $this->payloadBuilder->build($sitrep),
        ];
    }

    private function sourceSystem(): string
    {
        $sourceSystem = trim((string) $this->settings->get('relay_source_system', 'sitrep.app'));

        return $sourceSystem !== '' ? $sourceSystem : 'sitrep.app';
    }

    /**
     * @return array<int, string>
     */
    private function targetSystems(): array
    {
        $configured = (string) $this->settings->get('relay_target_systems', 'sitrep.ingestor');
        $targets = preg_split('/[\s,]+/', $configured) ?: [];
        $targets = array_values(array_unique(array_filter(array_map(
            fn (string $target): string => trim($target),
            $targets,
        ), fn (string $target): bool => $target !== '')));

        return $targets !== [] ? $targets : ['sitrep.ingestor'];
    }

    private function priority(SitrepReport $sitrep): string
    {
        return match ($sitrep->alert_level) {
            'Critical' => 'urgent',
            'Elevated' => 'high',
            default => 'normal',
        };
    }

    private function markFailed(SitrepRelayDelivery $delivery, string $message): SitrepRelayDelivery
    {
        $delivery->loadMissing('sitrepReport');
        $sitrep = $delivery->sitrepReport;

        $delivery->forceFill([
            'status' => SitrepRelayDelivery::STATUS_FAILED,
            'last_error' => Str::limit($message, 2000),
            'last_attempted_at' => now(),
        ])->save();

        Log::warning('SITREP Relay delivery marked failed.', array_merge(
            $this->logContext($delivery, $sitrep instanceof SitrepReport ? $sitrep : null),
            ['error' => Str::limit($message, 2000)],
        ));

        return $delivery;
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(SitrepRelayDelivery $delivery, ?SitrepReport $sitrep): array
    {
        return [
            'delivery_id' => $delivery->id,
            'sitrep_report_id' => $delivery->sitrep_report_id,
            'sitrep_sequence_number' => $sitrep?->sequence_number,
            'sitrep_generated_at' => $sitrep?->generated_at?->toIso8601String(),
            'attempt_count' => $delivery->attempt_count,
            'status' => $delivery->status,
            'source_system' => $this->sourceSystem(),
            'target_systems' => $this->targetSystems(),
        ];
    }
}
