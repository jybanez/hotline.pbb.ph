<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Http\Controllers\Controller;
use App\Support\Realtime\RealtimeEventPublishService;
use App\Support\Settings\SettingsService;
use App\Support\Sitreps\PeriodicSitrepSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RealtimeEventPublishService $realtimeEvents,
        private readonly PeriodicSitrepSchedule $periodicSitreps,
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

        $meta['sitrep_periodic'] = $this->periodicSitrepMeta();

        return array_filter([
            'items' => $items,
            'meta' => $meta !== [] ? $meta : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function periodicSitrepMeta(): array
    {
        $window = $this->periodicSitreps->window($this->settings);
        $alertLevel = $window['alert_level'];
        $intervalMinutes = $window['interval_minutes'];
        $periodStart = $window['period_started_at'];
        $periodEnd = $window['period_ended_at'];
        $nextDueAt = $window['next_due_at'];
        $latestReport = SitrepReport::query()
            ->whereNull('prepared_by_user_id')
            ->latest('generated_at')
            ->latest('id')
            ->first();

        return [
            'enabled' => $this->periodicSitreps->isEnabled($this->settings),
            'alert_level' => $alertLevel->value,
            'interval_minutes' => $intervalMinutes,
            'period_started_at' => $periodStart->toIso8601String(),
            'period_ended_at' => $periodEnd->toIso8601String(),
            'next_due_at' => $nextDueAt->toIso8601String(),
            'prepared_by_label' => 'System Generated',
            'coverage_source' => 'relay_hub_json',
            'latest_auto_sitrep' => $latestReport ? [
                'id' => $latestReport->id,
                'sequence_number' => $latestReport->sequence_number,
                'title' => $latestReport->title,
                'coverage_area' => $latestReport->coverage_area,
                'period_started_at' => $latestReport->period_started_at?->toIso8601String(),
                'period_ended_at' => $latestReport->period_ended_at?->toIso8601String(),
                'generated_at' => $latestReport->generated_at?->toIso8601String(),
                'status' => $latestReport->status,
                'visibility' => $latestReport->visibility,
            ] : null,
        ];
    }
}
