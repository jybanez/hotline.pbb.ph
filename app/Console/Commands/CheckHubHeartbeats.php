<?php

namespace App\Console\Commands;

use App\Models\Hub;
use App\Models\HubHeartbeatCheck;
use App\Models\Setting;
use App\Services\HubHeartbeatChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckHubHeartbeats extends Command
{
    protected $signature = 'app:check-hub-heartbeats';

    protected $description = 'Poll active hubs through Relay /api/status and persist heartbeat snapshot/history.';

    public function handle(HubHeartbeatChecker $checker): int
    {
        $policy = $this->policy();

        if (! $policy['enabled']) {
            $this->info('Heartbeat checks disabled.');
            return self::SUCCESS;
        }

        if ($policy['paused']) {
            $this->info('Heartbeat checks paused.');
            return self::SUCCESS;
        }

        if (! $this->intervalElapsed($policy['interval_minutes'])) {
            $this->info('Heartbeat interval has not elapsed yet.');
            return self::SUCCESS;
        }

        $hubs = Hub::query()
            ->where('status', 'active')
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->whereNotNull('relay_hub_id')
            ->where('relay_hub_id', '!=', '')
            ->orderBy('name')
            ->get();

        $summary = [
            'checked' => 0,
            'success' => 0,
            'failure' => 0,
        ];

        Log::info('Hub heartbeat cycle started.', [
            'hubs' => $hubs->count(),
            'timeout_seconds' => $policy['timeout_seconds'],
            'interval_minutes' => $policy['interval_minutes'],
        ]);

        foreach ($hubs as $hub) {
            $summary['checked']++;
            $result = $checker->check($hub, $policy['timeout_seconds']);
            $this->persistResult($hub, $result);
            if ($result['outcome'] === 'success') {
                $summary['success']++;
            } else {
                $summary['failure']++;
            }
        }

        Setting::setValue(
            'heartbeat_last_run_at',
            now()->toIso8601String(),
            'Last HQ heartbeat cycle run time.'
        );
        if ($summary['success'] > 0) {
            Setting::setValue(
                'heartbeat_last_success_at',
                now()->toIso8601String(),
                'Last HQ heartbeat cycle with at least one successful response.'
            );
        }

        Log::info('Hub heartbeat cycle finished.', $summary);
        $this->info(sprintf(
            'Hub heartbeat cycle finished. checked=%d success=%d failure=%d',
            $summary['checked'],
            $summary['success'],
            $summary['failure']
        ));

        return self::SUCCESS;
    }

    private function policy(): array
    {
        return [
            'enabled' => (bool) Setting::valueFor('heartbeat_enabled', true),
            'interval_minutes' => max(1, (int) Setting::valueFor('heartbeat_interval_minutes', 5)),
            'timeout_seconds' => max(1, (int) Setting::valueFor('heartbeat_timeout_seconds', 5)),
            'paused' => (bool) Setting::valueFor('heartbeat_paused', false),
        ];
    }

    private function intervalElapsed(int $intervalMinutes): bool
    {
        $lastRunAt = Setting::valueFor('heartbeat_last_run_at');
        if (! $lastRunAt) {
            return true;
        }

        try {
            return now()->diffInMinutes($lastRunAt) >= $intervalMinutes;
        } catch (\Throwable $error) {
            return true;
        }
    }

    private function persistResult(Hub $hub, array $result): void
    {
        HubHeartbeatCheck::query()->create([
            'hub_id' => $hub->id,
            'checked_at' => $result['checked_at'],
            'request_url' => $result['request_url'],
            'response_ms' => $result['response_ms'],
            'http_status' => $result['http_status'],
            'outcome' => $result['outcome'],
            'health_status' => $result['health_status'],
            'app_version' => $result['app_version'],
            'protocol_version' => $result['protocol_version'],
            'delivery_queued' => $result['delivery_queued'],
            'delivery_failed' => $result['delivery_failed'],
            'delivery_dead' => $result['delivery_dead'],
            'handlers_failed' => $result['handlers_failed'],
            'error_message' => $result['error_message'],
            'payload_json' => $result['payload_json'],
        ]);

        $hub->forceFill([
            'heartbeat_status' => $result['health_status'],
            'heartbeat_checked_at' => $result['checked_at'],
            'heartbeat_error' => $result['error_message'],
            'heartbeat_app_version' => $result['app_version'],
            'heartbeat_protocol_version' => $result['protocol_version'],
            'heartbeat_delivery_queued' => $result['delivery_queued'],
            'heartbeat_delivery_failed' => $result['delivery_failed'],
            'heartbeat_delivery_dead' => $result['delivery_dead'],
            'heartbeat_handlers_failed' => $result['handlers_failed'],
            'heartbeat_capabilities' => $result['capabilities'],
            'last_response_ms' => $result['response_ms'],
            'last_seen_at' => $result['outcome'] === 'success' ? $result['checked_at'] : $hub->last_seen_at,
        ])->save();
    }
}
