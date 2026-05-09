<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends BaseApiController
{
    public function show()
    {
        $settings = Setting::query()
            ->whereIn('key', [
                'country',
                'excluded_poi_classes',
                'heartbeat_enabled',
                'heartbeat_interval_minutes',
                'heartbeat_timeout_seconds',
                'heartbeat_paused',
                'heartbeat_pause_reason',
                'heartbeat_last_run_at',
                'heartbeat_last_success_at',
            ])
            ->get()
            ->keyBy('key');

        return $this->ok([
            'country' => $settings['country']->value['value'] ?? 'PH',
            'excluded_poi_classes' => $settings['excluded_poi_classes']->value['value'] ?? '',
            'heartbeat_enabled' => (bool) ($settings['heartbeat_enabled']->value['value'] ?? true),
            'heartbeat_interval_minutes' => (int) ($settings['heartbeat_interval_minutes']->value['value'] ?? 5),
            'heartbeat_timeout_seconds' => (int) ($settings['heartbeat_timeout_seconds']->value['value'] ?? 5),
            'heartbeat_paused' => (bool) ($settings['heartbeat_paused']->value['value'] ?? false),
            'heartbeat_pause_reason' => $settings['heartbeat_pause_reason']->value['value'] ?? '',
            'heartbeat_last_run_at' => $settings['heartbeat_last_run_at']->value['value'] ?? null,
            'heartbeat_last_success_at' => $settings['heartbeat_last_success_at']->value['value'] ?? null,
        ]);
    }

    public function update(Request $request)
    {
        $payload = $request->validate([
            'country' => ['required', 'string', 'size:2'],
            'excluded_poi_classes' => ['nullable', 'string'],
            'heartbeat_enabled' => ['nullable', 'boolean'],
            'heartbeat_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'heartbeat_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
            'heartbeat_paused' => ['nullable', 'boolean'],
            'heartbeat_pause_reason' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::setValue(
            'country',
            strtoupper($payload['country']),
            'Default country code.'
        );

        Setting::setValue(
            'excluded_poi_classes',
            $payload['excluded_poi_classes'] ?? '',
            'Comma-separated POI classes excluded from map rendering.'
        );

        Setting::setValue(
            'heartbeat_enabled',
            (bool) ($payload['heartbeat_enabled'] ?? true),
            'Whether HQ heartbeat polling is enabled.'
        );

        Setting::setValue(
            'heartbeat_interval_minutes',
            (int) ($payload['heartbeat_interval_minutes'] ?? 5),
            'Global heartbeat polling interval in minutes.'
        );

        Setting::setValue(
            'heartbeat_timeout_seconds',
            (int) ($payload['heartbeat_timeout_seconds'] ?? 5),
            'Per-hub heartbeat request timeout in seconds.'
        );

        Setting::setValue(
            'heartbeat_paused',
            (bool) ($payload['heartbeat_paused'] ?? false),
            'Whether HQ heartbeat polling is paused.'
        );

        Setting::setValue(
            'heartbeat_pause_reason',
            $payload['heartbeat_pause_reason'] ?? '',
            'Optional reason for pausing HQ heartbeat polling.'
        );

        return $this->ok();
    }
}
