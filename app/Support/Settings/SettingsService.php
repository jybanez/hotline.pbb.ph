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
            'realtime_client_code' => 'clt_PBB_HOTLINE',
            'realtime_project_code_server' => 'prj_HOTLINE_SERVER',
            'realtime_project_code_caller' => 'prj_HOTLINE_CITIZEN',
            'realtime_project_code_operator' => 'prj_HOTLINE_OPERATOR',
            'realtime_project_code_command' => 'prj_HOTLINE_COMMAND',
            'realtime_project_code_media_ingest' => 'prj_HOTLINE_OPERATOR',
            'realtime_url' => 'https://realtime.pbb.ph',
            'realtime_backend_ingress_secret' => '',
            'realtime_media_ingest_secret' => '',
            'realtime_token_signing_secret' => '',
            'relay_url' => 'https://relay.pbb.ph',
            'relay_token' => '',
            'map_server_url' => 'https://mapserver.pbb.ph',
            'sitrep_periodic_generation_enabled' => true,
            'sitrep_periodic_normal_interval_minutes' => 1440,
            'sitrep_periodic_elevated_interval_minutes' => 360,
            'sitrep_periodic_critical_interval_minutes' => 60,
            'sitrep_periodic_coverage_area' => '',
            'sitrep_periodic_prepared_by_user_id' => null,
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
