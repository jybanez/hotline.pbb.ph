<?php

namespace App\Support\Settings;

use App\Domain\Shared\Enums\AlertLevel;
use App\Models\Setting;

class SettingsService
{
    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'call_hold_seconds' => 1,
            'call_timeout_seconds' => 20,
            'reconnect_timeout_seconds' => 20,
            'alert_level' => AlertLevel::Normal->value,
            'alert_voice' => 'default',
            'audio_graph_style' => 'tsunami',
            'realtime_client_code' => 'clt_01KMXFPRXCTHJAG10DMACJFMYB',
            'realtime_project_code_server' => 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6',
            'realtime_project_code_caller' => 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9',
            'realtime_project_code_operator' => 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6',
            'realtime_project_code_command' => 'prj_hotline_command',
            'realtime_project_code_media_ingest' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
            'realtime_url' => 'https://realtime.pbb.ph',
            'realtime_backend_ingress_secret' => '',
            'realtime_media_ingest_secret' => '',
            'realtime_token_signing_secret' => '',
            'relay_url' => 'https://relay.pbb.ph',
            'relay_token' => '',
            'map_server_url' => 'https://mapserver.pbb.ph',
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $fallback = $default ?? ($this->defaults()[$key] ?? null);
        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null) {
            return $fallback;
        }

        return $setting->value['value'] ?? $fallback;
    }

    public function set(string $key, mixed $value): Setting
    {
        return Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]],
        );
    }

    public function currentAlertLevel(): AlertLevel
    {
        $value = (string) $this->get('alert_level', AlertLevel::Normal->value);

        return AlertLevel::tryFrom($value) ?? AlertLevel::Normal;
    }
}
