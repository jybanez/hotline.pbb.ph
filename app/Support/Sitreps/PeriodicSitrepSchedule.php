<?php

namespace App\Support\Sitreps;

use App\Domain\Shared\Enums\AlertLevel;
use App\Support\Settings\SettingsService;
use Illuminate\Support\Carbon;

class PeriodicSitrepSchedule
{
    public function isEnabled(SettingsService $settings): bool
    {
        return $this->booleanSetting($settings->get('sitrep_periodic_generation_enabled', true));
    }

    public function intervalMinutes(SettingsService $settings, AlertLevel $alertLevel): int
    {
        $key = match ($alertLevel) {
            AlertLevel::Critical => 'sitrep_periodic_critical_interval_minutes',
            AlertLevel::Elevated => 'sitrep_periodic_elevated_interval_minutes',
            AlertLevel::Normal => 'sitrep_periodic_normal_interval_minutes',
        };

        return max(1, (int) $settings->get($key));
    }

    /**
     * @return array{alert_level: AlertLevel, interval_minutes: int, period_started_at: Carbon, period_ended_at: Carbon, next_due_at: Carbon}
     */
    public function window(SettingsService $settings, ?Carbon $now = null): array
    {
        $alertLevel = $settings->currentAlertLevel();
        $intervalMinutes = $this->intervalMinutes($settings, $alertLevel);
        $periodEnd = $this->lastCompletedWindowEnd($now ?? Carbon::now(), $intervalMinutes);

        return [
            'alert_level' => $alertLevel,
            'interval_minutes' => $intervalMinutes,
            'period_started_at' => $periodEnd->copy()->subMinutes($intervalMinutes),
            'period_ended_at' => $periodEnd,
            'next_due_at' => $periodEnd->copy()->addMinutes($intervalMinutes),
        ];
    }

    public function lastCompletedWindowEnd(Carbon $now, int $intervalMinutes): Carbon
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $localized = $now->copy()->setTimezone($timezone)->startOfMinute();
        $dayStart = $localized->copy()->startOfDay();
        $minutesSinceDayStart = $dayStart->diffInMinutes($localized);
        $completedIntervals = intdiv($minutesSinceDayStart, $intervalMinutes);

        return $dayStart->copy()->addMinutes($completedIntervals * $intervalMinutes);
    }

    private function booleanSetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
