<?php

namespace App\Support\Realtime;

use App\Domain\Command\Models\CommandBroadcast;
use App\Domain\Media\Models\Media;
use App\Support\Settings\SettingsService;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class RealtimeEventPublishService
{
    public const SETTINGS_ROOM = 'hotline.settings.global';
    public const BROADCAST_ROOM = 'hotline.broadcast.global';
    public const INCIDENT_CHAT_ROOM_PREFIX = 'chat.thread.incident.';
    public const INCIDENT_MEDIA_ROOM_PREFIX = 'hotline.media.incident.';

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function publishAlertLevelChanged(string $alertLevel): array
    {
        return $this->publish(
            projectCode: $this->projectCode('server', 'prj_hotline_server'),
            room: self::SETTINGS_ROOM,
            eventType: 'hotline.alert_level.changed',
            payload: [
                'alert_level' => $alertLevel,
                'changed_at' => now()->toIso8601String(),
            ],
            meta: [
                'source_module' => 'hotline-beta-admin',
            ],
            eventId: 'evt_hotline_alert_' . Str::ulid(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function publishIncidentMediaProcessing(Media $media): array
    {
        return $this->publishIncidentMediaEvent('media.processing', $media);
    }

    /**
     * @return array<string, mixed>
     */
    public function publishIncidentMediaAvailable(Media $media): array
    {
        return $this->publishIncidentMediaEvent('media.available', $media);
    }

    /**
     * @return array<string, mixed>
     */
    public function publishCommandBroadcast(CommandBroadcast $broadcast): array
    {
        return $this->publish(
            projectCode: $this->projectCode('server', 'prj_hotline_server'),
            room: self::BROADCAST_ROOM,
            eventType: 'hotline.broadcast.created',
            payload: [
                'id' => (int) $broadcast->id,
                'title' => $broadcast->title,
                'message' => $broadcast->message,
                'tone' => $broadcast->tone,
                'audience' => $broadcast->audience,
                'target_roles' => $broadcast->target_roles_json ?? [],
                'created_by' => [
                    'id' => $broadcast->creator?->id,
                    'name' => $broadcast->creator?->name,
                    'role' => $broadcast->creator?->role?->value ?? (string) $broadcast->creator?->role,
                ],
                'published_at' => $broadcast->published_at?->toIso8601String(),
                'expires_at' => $broadcast->expires_at?->toIso8601String(),
            ],
            meta: [
                'source_module' => 'hotline-beta-command',
            ],
            eventId: 'evt_hotline_broadcast_' . Str::ulid(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function publishProductQueryResponse(string $room, array $payload): array
    {
        return $this->publish(
            projectCode: $this->projectCode('server', 'prj_hotline_server'),
            room: $room,
            eventType: 'product.query.response',
            payload: $payload,
            meta: [
                'source' => 'backend',
                'source_module' => 'hotline-beta',
            ],
            eventId: 'evt_hotline_product_query_' . Str::ulid(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function publish(
        string $projectCode,
        string $room,
        string $eventType,
        array $payload,
        array $meta = [],
        ?string $eventId = null,
    ): array {
        $startedAt = microtime(true);
        $traceId = 'hotline_rtpub_' . Str::lower((string) Str::ulid());
        $transferStats = [];
        $backendSecret = trim((string) $this->settings->get('realtime_backend_ingress_secret', ''));

        if ($backendSecret === '') {
            return [
                'status' => 'skipped',
                'message' => 'Realtime backend ingress secret is not configured.',
                'hotline_trace_id' => $traceId,
            ];
        }

        $clientCode = trim((string) $this->settings->get('realtime_client_code'));

        if ($clientCode === '') {
            return [
                'status' => 'skipped',
                'message' => 'Realtime client code is not configured.',
                'hotline_trace_id' => $traceId,
            ];
        }

        $normalizedProjectCode = trim($projectCode);

        if ($normalizedProjectCode === '') {
            return [
                'status' => 'skipped',
                'message' => 'Realtime server project code is not configured.',
                'hotline_trace_id' => $traceId,
            ];
        }

        $endpoint = $this->eventPublishEndpoint((string) $this->settings->get('realtime_url', 'https://realtime.pbb.ph'));
        $requestHeaders = [
            'X-Realtime-Backend-Secret' => $backendSecret,
        ];

        if ($this->shouldBypassRealtimeProxy($endpoint)) {
            $requestHeaders['Host'] = 'realtime.pbb.ph';
            $endpoint = 'http://127.0.0.1/api/v1/events/publish';
        }

        try {
            $response = $this->requestFactory($transferStats)
                ->withHeaders($requestHeaders)
                ->post($endpoint, [
                    'client_code' => $clientCode,
                    'project_code' => $normalizedProjectCode,
                    'room' => trim($room),
                    'event_type' => trim($eventType),
                    'payload' => $payload,
                    'meta' => $meta,
                    'event_id' => $eventId,
                ]);

            $data = $response->json();
            $realtimeTraceId = $response->header('X-Realtime-Trace-Id');
            $elapsedMs = round((microtime(true) - $startedAt) * 1000, 3);

            Log::info('Hotline realtime publish trace.', [
                'hotline_trace_id' => $traceId,
                'realtime_trace_id' => $realtimeTraceId,
                'endpoint' => $endpoint,
                'http_status' => $response->status(),
                'elapsed_ms' => $elapsedMs,
                'client_code' => $clientCode,
                'project_code' => $normalizedProjectCode,
                'room' => trim($room),
                'event_type' => trim($eventType),
                'request_headers' => [
                    'host' => $requestHeaders['Host'] ?? null,
                ],
                'transfer_stats' => $transferStats,
            ]);

            if ($response->successful()) {
                return [
                    'status' => 'accepted',
                    'message' => 'Realtime alert broadcast queued.',
                    'hotline_trace_id' => $traceId,
                    'realtime_trace_id' => $realtimeTraceId,
                    'elapsed_ms' => $elapsedMs,
                    'http_status' => $response->status(),
                    'response' => is_array($data) ? $data : [],
                    'transfer_stats' => $transferStats,
                ];
            }

            return [
                'status' => 'rejected',
                'message' => (string) ($data['message'] ?? 'Realtime rejected the alert broadcast request.'),
                'reason' => $data['reason'] ?? null,
                'hotline_trace_id' => $traceId,
                'realtime_trace_id' => $realtimeTraceId,
                'elapsed_ms' => $elapsedMs,
                'http_status' => $response->status(),
                'response' => is_array($data) ? $data : [],
                'transfer_stats' => $transferStats,
            ];
        } catch (ConnectionException|RequestException $exception) {
            $elapsedMs = round((microtime(true) - $startedAt) * 1000, 3);

            Log::warning('Hotline realtime publish request failed.', [
                'hotline_trace_id' => $traceId,
                'endpoint' => $endpoint,
                'elapsed_ms' => $elapsedMs,
                'client_code' => $clientCode,
                'project_code' => $normalizedProjectCode,
                'room' => trim($room),
                'event_type' => trim($eventType),
                'transfer_stats' => $transferStats,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);

            if (str_contains($exception->getMessage(), 'cURL error 28')) {
                return [
                    'status' => 'pending',
                    'message' => 'Realtime alert broadcast acknowledgement timed out. Delivery may still complete.',
                    'reason' => 'timeout',
                    'hotline_trace_id' => $traceId,
                    'elapsed_ms' => $elapsedMs,
                    'transfer_stats' => $transferStats,
                ];
            }

            return [
                'status' => 'rejected',
                'message' => 'Realtime alert broadcast request failed: ' . $exception->getMessage(),
                'hotline_trace_id' => $traceId,
                'elapsed_ms' => $elapsedMs,
                'transfer_stats' => $transferStats,
            ];
        } catch (Throwable $exception) {
            $elapsedMs = round((microtime(true) - $startedAt) * 1000, 3);

            Log::warning('Hotline realtime publish request failed.', [
                'hotline_trace_id' => $traceId,
                'endpoint' => $endpoint,
                'elapsed_ms' => $elapsedMs,
                'client_code' => $clientCode,
                'project_code' => $normalizedProjectCode,
                'room' => trim($room),
                'event_type' => trim($eventType),
                'transfer_stats' => $transferStats,
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);

            return [
                'status' => 'rejected',
                'message' => 'Realtime alert broadcast request failed: ' . $exception->getMessage(),
                'hotline_trace_id' => $traceId,
                'elapsed_ms' => $elapsedMs,
                'transfer_stats' => $transferStats,
            ];
        }
    }

    private static function secondsToMilliseconds(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round(((float) $value) * 1000, 3);
    }

    /**
     * @param array<string, mixed> $transferStats
     */
    private function requestFactory(array &$transferStats): PendingRequest
    {
        $options = [
            'version' => 1.1,
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ],
            'on_stats' => static function (TransferStats $stats) use (&$transferStats): void {
                $handlerStats = $stats->getHandlerStats();

                $transferStats = [
                    'effective_uri' => (string) $stats->getEffectiveUri(),
                    'requested_http_version' => '1.1',
                    'namelookup_time_ms' => self::secondsToMilliseconds($handlerStats['namelookup_time'] ?? null),
                    'connect_time_ms' => self::secondsToMilliseconds($handlerStats['connect_time'] ?? null),
                    'appconnect_time_ms' => self::secondsToMilliseconds($handlerStats['appconnect_time'] ?? null),
                    'pretransfer_time_ms' => self::secondsToMilliseconds($handlerStats['pretransfer_time'] ?? null),
                    'starttransfer_time_ms' => self::secondsToMilliseconds($handlerStats['starttransfer_time'] ?? null),
                    'total_time_ms' => self::secondsToMilliseconds($handlerStats['total_time'] ?? null),
                    'primary_ip' => $handlerStats['primary_ip'] ?? null,
                    'primary_port' => $handlerStats['primary_port'] ?? null,
                    'http_version' => $handlerStats['http_version'] ?? null,
                    'redirect_count' => $handlerStats['redirect_count'] ?? null,
                ];
            },
        ];

        $caBundle = $this->configuredCaBundle();
        if ($caBundle !== null) {
            $options['verify'] = $caBundle;
        }

        return Http::acceptJson()
            ->connectTimeout(3)
            ->timeout(15)
            ->withOptions($options);
    }

    private function configuredCaBundle(): ?string
    {
        $path = trim((string) config('services.realtime_publish.ca_bundle', ''));

        return $path !== '' && is_file($path) ? $path : null;
    }

    private function shouldBypassRealtimeProxy(string $endpoint): bool
    {
        if (!app()->environment('local')) {
            return false;
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, 'realtime.pbb.ph') === 0;
    }

    private function projectCode(string $scope, string $fallback): string
    {
        $value = trim((string) $this->settings->get('realtime_project_code_' . $scope));

        return $value !== '' ? $value : $fallback;
    }

    private function eventPublishEndpoint(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return 'https://realtime.pbb.ph/api/v1/events/publish';
        }

        if (Str::startsWith($trimmed, 'wss://')) {
            $trimmed = 'https://' . ltrim(Str::after($trimmed, 'wss://'), '/');
        } elseif (Str::startsWith($trimmed, 'ws://')) {
            $trimmed = 'http://' . ltrim(Str::after($trimmed, 'ws://'), '/');
        } elseif (!Str::startsWith($trimmed, ['https://', 'http://'])) {
            $trimmed = 'https://' . ltrim($trimmed, '/');
        }

        $trimmed = preg_replace('#/realtime/?$#', '', $trimmed) ?? $trimmed;

        return rtrim($trimmed, '/') . '/api/v1/events/publish';
    }

    /**
     * @return array<string, mixed>
     */
    private function publishIncidentMediaEvent(string $eventType, Media $media): array
    {
        return $this->publish(
            projectCode: $this->projectCode('server', 'prj_hotline_server'),
            room: self::INCIDENT_MEDIA_ROOM_PREFIX . (int) $media->incident_id,
            eventType: $eventType,
            payload: [
                'incident_id' => (int) $media->incident_id,
                'call_session_id' => (int) $media->call_session_id,
                'media' => $this->serializeMedia($media),
            ],
            meta: [
                'source_module' => 'hotline-beta-media',
            ],
            eventId: 'evt_hotline_media_' . Str::ulid(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMedia(Media $media): array
    {
        return [
            'id' => (int) $media->id,
            'incident_id' => (int) $media->incident_id,
            'call_session_id' => (int) $media->call_session_id,
            'type' => (string) $media->type,
            'peer_user_id' => $media->peer_user_id !== null ? (int) $media->peer_user_id : null,
            'peer_role' => $media->peer_role,
            'peer_label' => $media->peer_label,
            'path' => $media->available_at ? (string) $media->path : null,
            'duration_seconds' => $media->duration_seconds !== null ? (int) $media->duration_seconds : null,
            'metadata' => $media->metadata_json ?? [],
            'processing' => $media->available_at === null,
            'created_at' => $media->created_at?->toIso8601String(),
            'available_at' => $media->available_at?->toIso8601String(),
        ];
    }
}
