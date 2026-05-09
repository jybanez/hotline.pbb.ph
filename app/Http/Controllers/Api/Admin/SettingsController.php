<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Realtime\RealtimeEventPublishService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RealtimeEventPublishService $realtimeEvents,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $traceId = 'hotline_settings_' . Str::lower((string) Str::ulid());
        $startedAt = microtime(true);
        $marks = [];
        $mark = function (string $stage, array $context = []) use (&$marks, $startedAt): void {
            $marks[] = array_merge([
                'stage' => $stage,
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
            ], $context);
        };

        $mark('controller.enter');
        $previousAlertLevel = (string) $this->settings->get('alert_level');
        $mark('previous_alert_level.loaded', ['alert_level' => $previousAlertLevel]);

        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.key' => ['required', 'string'],
            'items.*.value' => ['nullable'],
        ]);
        $mark('validated', ['item_count' => count($validated['items'])]);

        foreach ($validated['items'] as $item) {
            $this->settings->set($item['key'], $item['value'] ?? null);
        }
        $mark('settings.saved');

        $nextAlertLevel = (string) $this->settings->get('alert_level');
        $meta = [];
        $mark('next_alert_level.loaded', ['alert_level' => $nextAlertLevel]);

        if ($previousAlertLevel !== $nextAlertLevel) {
            $meta['realtime_publish'] = $this->realtimeEvents->publishAlertLevelChanged($nextAlertLevel);
            $mark('realtime_publish.completed', [
                'publish_status' => $meta['realtime_publish']['status'] ?? null,
                'realtime_trace_id' => $meta['realtime_publish']['realtime_trace_id'] ?? null,
                'hotline_publish_trace_id' => $meta['realtime_publish']['hotline_trace_id'] ?? null,
                'publish_elapsed_ms' => $meta['realtime_publish']['elapsed_ms'] ?? null,
            ]);
        }

        $payload = $this->payload($meta);
        $mark('payload.built');

        Log::info('Hotline admin settings update trace.', [
            'trace_id' => $traceId,
            'total_ms' => round((microtime(true) - $startedAt) * 1000, 3),
            'marks' => $marks,
            'realtime_meta' => $meta['realtime_publish'] ?? null,
        ]);

        return response()->json($payload)->header('X-Hotline-Trace-Id', $traceId);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function payload(array $meta = []): array
    {
        $items = [];

        foreach ($this->settings->defaults() as $key => $default) {
            $items[] = [
                'key' => $key,
                'value' => $this->settings->get($key, $default),
            ];
        }

        return array_filter([
            'items' => $items,
            'meta' => $meta !== [] ? $meta : null,
        ], static fn ($value) => $value !== null);
    }
}
