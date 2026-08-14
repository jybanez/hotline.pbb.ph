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
            'callback_first_sla_seconds' => 30,
            'callback_max_additional_attempts' => 2,
            'callback_retry_window_minutes' => 5,
            'callback_overdue_warning_seconds' => 15,
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
            'relay_source_system' => 'sitrep.app',
            'relay_target_systems' => 'sitrep.ingestor',
            'support_request_relay_source_system' => 'hotline.command',
            'support_request_relay_target_systems' => 'support.dispatch',
            'support_request_relay_handler_token' => '',
            'incident_relay_enabled' => false,
            'incident_relay_source_system' => 'hotline.incident',
            'incident_relay_target_systems' => 'utility.vena',
            'incident_relay_debounce_seconds' => 10,
            'sitrep_media_access_token' => '',
            'media_archive_root' => '',
            'account_sso_enabled' => (bool) config('account.enabled', false),
            'account_sso_base_url' => (string) config('account.base_url', 'https://account.pbb.ph'),
            'account_sso_client_id' => (string) config('account.client_id', 'pbb-hotline'),
            'account_sso_client_secret' => (string) config('account.client_secret', ''),
            'account_sso_redirect_uri' => (string) config('account.redirect_uri', 'https://hotline.pbb.ph/auth/account/callback'),
            'account_sso_post_logout_redirect_uri' => (string) config('account.post_logout_redirect_uri', 'https://hotline.pbb.ph'),
            'account_sso_scopes' => implode(' ', (array) config('account.scopes', ['openid', 'profile'])),
            'account_sso_timeout_seconds' => (int) config('account.timeout_seconds', 10),
            'account_sso_ca_bundle' => (string) config('account.ca_bundle', ''),
            'account_admin_api_enabled' => false,
            'account_admin_api_token' => '',
            'account_admin_api_client' => 'pbb-account',
            'map_server_url' => 'https://mapserver.pbb.ph',
            'sitrep_periodic_generation_enabled' => true,
            'sitrep_periodic_normal_interval_minutes' => 240,
            'sitrep_periodic_elevated_interval_minutes' => 60,
            'sitrep_periodic_critical_interval_minutes' => 15,
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
