<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class HubHeartbeatChecker
{
    public function check(Hub $hub, int $timeoutSeconds = 5): array
    {
        $checkedAt = now();
        $requestUrl = $this->statusUrlForHub($hub);
        $startedAt = microtime(true);

        try {
            $response = Http::acceptJson()
                ->timeout($timeoutSeconds)
                ->get($requestUrl);
        } catch (\Throwable $error) {
            return [
                'checked_at' => $checkedAt,
                'request_url' => $requestUrl,
                'response_ms' => $this->elapsedMs($startedAt),
                'http_status' => null,
                'outcome' => 'network_error',
                'health_status' => 'offline',
                'app_version' => null,
                'protocol_version' => null,
                'delivery_queued' => null,
                'delivery_failed' => null,
                'delivery_dead' => null,
                'handlers_failed' => null,
                'capabilities' => [],
                'error_message' => $error->getMessage(),
                'payload_json' => null,
            ];
        }

        $responseMs = $this->elapsedMs($startedAt);
        $payload = $response->json();

        if (! $response->successful() || !is_array($payload)) {
            return [
                'checked_at' => $checkedAt,
                'request_url' => $requestUrl,
                'response_ms' => $responseMs,
                'http_status' => $response->status(),
                'outcome' => 'invalid_payload',
                'health_status' => 'unhealthy',
                'app_version' => null,
                'protocol_version' => null,
                'delivery_queued' => null,
                'delivery_failed' => null,
                'delivery_dead' => null,
                'handlers_failed' => null,
                'capabilities' => [],
                'error_message' => 'Status endpoint did not return the expected JSON payload.',
                'payload_json' => $payload,
            ];
        }

        $normalized = $this->normalizePayload($payload);

        if (! $normalized['valid']) {
            return [
                'checked_at' => $checkedAt,
                'request_url' => $requestUrl,
                'response_ms' => $responseMs,
                'http_status' => $response->status(),
                'outcome' => 'invalid_payload',
                'health_status' => 'unhealthy',
                'app_version' => $normalized['app_version'],
                'protocol_version' => $normalized['protocol_version'],
                'delivery_queued' => $normalized['delivery_queued'],
                'delivery_failed' => $normalized['delivery_failed'],
                'delivery_dead' => $normalized['delivery_dead'],
                'handlers_failed' => $normalized['handlers_failed'],
                'capabilities' => $normalized['capabilities'],
                'error_message' => $normalized['error_message'],
                'payload_json' => $payload,
            ];
        }

        if ((string) $hub->relay_hub_id !== (string) $normalized['local_hub_id']) {
            return [
                'checked_at' => $checkedAt,
                'request_url' => $requestUrl,
                'response_ms' => $responseMs,
                'http_status' => $response->status(),
                'outcome' => 'identity_mismatch',
                'health_status' => 'unhealthy',
                'app_version' => $normalized['app_version'],
                'protocol_version' => $normalized['protocol_version'],
                'delivery_queued' => $normalized['delivery_queued'],
                'delivery_failed' => $normalized['delivery_failed'],
                'delivery_dead' => $normalized['delivery_dead'],
                'handlers_failed' => $normalized['handlers_failed'],
                'capabilities' => $normalized['capabilities'],
                'error_message' => sprintf(
                    'Expected relay_hub_id "%s" but received "%s".',
                    $hub->relay_hub_id,
                    $normalized['local_hub_id']
                ),
                'payload_json' => $payload,
            ];
        }

        return [
            'checked_at' => $checkedAt,
            'request_url' => $requestUrl,
            'response_ms' => $responseMs,
            'http_status' => $response->status(),
            'outcome' => 'success',
            'health_status' => $normalized['health_status'],
            'app_version' => $normalized['app_version'],
            'protocol_version' => $normalized['protocol_version'],
            'delivery_queued' => $normalized['delivery_queued'],
            'delivery_failed' => $normalized['delivery_failed'],
            'delivery_dead' => $normalized['delivery_dead'],
            'handlers_failed' => $normalized['handlers_failed'],
            'capabilities' => $normalized['capabilities'],
            'error_message' => null,
            'payload_json' => $payload,
        ];
    }

    private function statusUrlForHub(Hub $hub): string
    {
        $domain = trim((string) $hub->domain);
        if ($domain === '') {
            return '/api/status';
        }
        if (preg_match('/^https?:\/\//i', $domain)) {
            return rtrim($domain, '/') . '/api/status';
        }

        return 'https://' . rtrim($domain, '/') . '/api/status';
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function normalizePayload(array $payload): array
    {
        $topLevelKeys = ['app', 'relay', 'hub', 'health', 'queues', 'capabilities', 'timestamp'];
        foreach ($topLevelKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                return $this->invalid(sprintf('Missing top-level key "%s".', $key), $payload);
            }
        }

        $healthStatus = strtolower(trim((string) Arr::get($payload, 'health.status', '')));
        if (!in_array($healthStatus, ['healthy', 'degraded', 'unhealthy'], true)) {
            return $this->invalid('Invalid health.status value.', $payload);
        }

        $localHubId = trim((string) Arr::get($payload, 'hub.local_hub_id', ''));
        if ($localHubId === '') {
            return $this->invalid('Missing hub.local_hub_id.', $payload);
        }

        if (!is_array($payload['capabilities'])) {
            return $this->invalid('Capabilities must be an array.', $payload);
        }

        return [
            'valid' => true,
            'error_message' => null,
            'local_hub_id' => $localHubId,
            'health_status' => $healthStatus,
            'app_version' => Arr::get($payload, 'app.version'),
            'protocol_version' => Arr::get($payload, 'relay.protocol_version'),
            'delivery_queued' => (int) Arr::get($payload, 'queues.delivery.queued', 0),
            'delivery_failed' => (int) Arr::get($payload, 'queues.delivery.failed', 0),
            'delivery_dead' => (int) Arr::get($payload, 'queues.delivery.dead', 0),
            'handlers_failed' => (int) Arr::get($payload, 'queues.handlers.failed', 0),
            'capabilities' => array_values(array_map('strval', $payload['capabilities'])),
        ];
    }

    private function invalid(string $errorMessage, array $payload): array
    {
        return [
            'valid' => false,
            'error_message' => $errorMessage,
            'local_hub_id' => trim((string) Arr::get($payload, 'hub.local_hub_id', '')),
            'health_status' => strtolower(trim((string) Arr::get($payload, 'health.status', 'unhealthy'))) ?: 'unhealthy',
            'app_version' => Arr::get($payload, 'app.version'),
            'protocol_version' => Arr::get($payload, 'relay.protocol_version'),
            'delivery_queued' => Arr::get($payload, 'queues.delivery.queued'),
            'delivery_failed' => Arr::get($payload, 'queues.delivery.failed'),
            'delivery_dead' => Arr::get($payload, 'queues.delivery.dead'),
            'handlers_failed' => Arr::get($payload, 'queues.handlers.failed'),
            'capabilities' => is_array(Arr::get($payload, 'capabilities')) ? array_values(array_map('strval', Arr::get($payload, 'capabilities', []))) : [],
        ];
    }
}
