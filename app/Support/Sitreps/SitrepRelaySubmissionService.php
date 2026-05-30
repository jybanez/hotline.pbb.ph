<?php

namespace App\Support\Sitreps;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Support\Settings\SettingsService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
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
                ->withHeaders(['X-Relay-Key' => $relayToken])
                ->timeout(10)
                ->post($relayUrl.'/api/v1/messages', $this->envelope($sitrep));

            if (! $response->successful()) {
                return $this->markFailed($delivery, sprintf(
                    'Relay rejected SITREP handoff with HTTP %d: %s',
                    $response->status(),
                    Str::limit($response->body(), 500),
                ));
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

            return $delivery;
        } catch (RequestException $exception) {
            return $this->markFailed($delivery, $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

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
        $delivery->forceFill([
            'status' => SitrepRelayDelivery::STATUS_FAILED,
            'last_error' => Str::limit($message, 2000),
            'last_attempted_at' => now(),
        ])->save();

        return $delivery;
    }
}
