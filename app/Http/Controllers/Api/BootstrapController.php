<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use Illuminate\Http\Request;

class BootstrapController extends BaseApiController
{
    public function show(Request $request)
    {
        $authUser = $request->user();
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

        $excludedPoiClasses = collect(explode(',', (string) ($settings['excluded_poi_classes']->value['value'] ?? '')))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();

        return $this->ok([
            'app' => [
                'name' => 'PBB - HQ',
                'page' => StringablePage::from($request->query('page')),
            ],
            'auth' => [
                'authenticated' => (bool) $authUser,
                'account' => $authUser ? [
                    'id' => $authUser->id,
                    'name' => $authUser->name,
                    'email' => $authUser->email,
                    'role' => $authUser->role,
                ] : null,
            ],
            'security' => [
                'csrfToken' => $request->session()->token(),
                'sessionLifetimeMinutes' => (int) config('session.lifetime', 120),
            ],
            'settings' => [
                'country' => strtoupper((string) ($settings['country']->value['value'] ?? 'PH')),
                'excludedPoiClasses' => $excludedPoiClasses,
                'heartbeat' => [
                    'enabled' => (bool) ($settings['heartbeat_enabled']->value['value'] ?? true),
                    'intervalMinutes' => (int) ($settings['heartbeat_interval_minutes']->value['value'] ?? 5),
                    'timeoutSeconds' => (int) ($settings['heartbeat_timeout_seconds']->value['value'] ?? 5),
                    'paused' => (bool) ($settings['heartbeat_paused']->value['value'] ?? false),
                    'pauseReason' => (string) ($settings['heartbeat_pause_reason']->value['value'] ?? ''),
                    'lastRunAt' => $settings['heartbeat_last_run_at']->value['value'] ?? null,
                    'lastSuccessAt' => $settings['heartbeat_last_success_at']->value['value'] ?? null,
                ],
            ],
        ]);
    }
}

final class StringablePage
{
    public static function from($value): string
    {
        $page = trim((string) $value);

        return $page !== '' ? $page : 'home';
    }
}
