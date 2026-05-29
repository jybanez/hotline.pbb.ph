<?php

namespace App\Console\Commands;

use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Support\Settings\SettingsService;
use App\Support\Sitreps\SitrepGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GeneratePeriodicSitrep extends Command
{
    protected $signature = 'app:generate-periodic-sitrep
        {--force : Generate even when disabled or when the window already has a report}
        {--dry-run : Show the computed reporting window without creating a report}';

    protected $description = 'Generate a private draft SITREP for the last completed alert-level reporting window.';

    public function handle(SettingsService $settings, SitrepGenerationService $sitreps): int
    {
        $forced = (bool) $this->option('force');

        if (! $forced && ! $this->booleanSetting($settings->get('sitrep_periodic_generation_enabled', true))) {
            $this->info('Periodic SITREP generation is disabled.');

            return self::SUCCESS;
        }

        $alertLevel = $settings->currentAlertLevel();
        $intervalMinutes = $this->intervalMinutes($settings, $alertLevel);
        $periodEnd = $this->lastCompletedWindowEnd(Carbon::now(), $intervalMinutes);
        $periodStart = $periodEnd->copy()->subMinutes($intervalMinutes);

        if (! $forced && $this->reportExistsForWindow($periodStart, $periodEnd)) {
            $this->info(sprintf(
                'Periodic SITREP already exists for %s to %s.',
                $periodStart->toIso8601String(),
                $periodEnd->toIso8601String(),
            ));

            return self::SUCCESS;
        }

        $title = sprintf(
            'PBB Hotline %s SITREP - %s',
            $alertLevel->value,
            $periodEnd->format('Y-m-d H:i'),
        );

        $payload = [
            'title' => $title,
            'period_started_at' => $periodStart->toIso8601String(),
            'period_ended_at' => $periodEnd->toIso8601String(),
            'status' => 'draft',
            'visibility' => 'private',
            'system_generated' => true,
        ];

        if ((bool) $this->option('dry-run')) {
            $this->line(sprintf('Alert level: %s', $alertLevel->value));
            $this->line(sprintf('Interval minutes: %d', $intervalMinutes));
            $this->line(sprintf('Period start: %s', $periodStart->toIso8601String()));
            $this->line(sprintf('Period end: %s', $periodEnd->toIso8601String()));
            $this->line('Prepared by: System Generated');
            $this->line('Coverage area: Relay hub identity');

            return self::SUCCESS;
        }

        $report = $sitreps->generate(null, $payload);

        Log::info('Periodic SITREP generated.', [
            'sitrep_id' => $report->id,
            'sequence_number' => $report->sequence_number,
            'alert_level' => $alertLevel->value,
            'interval_minutes' => $intervalMinutes,
            'period_started_at' => $periodStart->toIso8601String(),
            'period_ended_at' => $periodEnd->toIso8601String(),
            'prepared_by_user_id' => null,
        ]);

        $this->info(sprintf(
            'Generated periodic SITREP #%04d for %s to %s.',
            $report->sequence_number,
            $periodStart->toIso8601String(),
            $periodEnd->toIso8601String(),
        ));

        return self::SUCCESS;
    }

    private function intervalMinutes(SettingsService $settings, AlertLevel $alertLevel): int
    {
        $key = match ($alertLevel) {
            AlertLevel::Critical => 'sitrep_periodic_critical_interval_minutes',
            AlertLevel::Elevated => 'sitrep_periodic_elevated_interval_minutes',
            AlertLevel::Normal => 'sitrep_periodic_normal_interval_minutes',
        };

        return max(1, (int) $settings->get($key));
    }

    private function lastCompletedWindowEnd(Carbon $now, int $intervalMinutes): Carbon
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $localized = $now->copy()->setTimezone($timezone)->startOfMinute();
        $dayStart = $localized->copy()->startOfDay();
        $minutesSinceDayStart = $dayStart->diffInMinutes($localized);
        $completedIntervals = intdiv($minutesSinceDayStart, $intervalMinutes);
        $windowEnd = $dayStart->copy()->addMinutes($completedIntervals * $intervalMinutes);

        if ($windowEnd->equalTo($localized)) {
            return $windowEnd;
        }

        return $windowEnd;
    }

    private function reportExistsForWindow(Carbon $periodStart, Carbon $periodEnd): bool
    {
        return SitrepReport::query()
            ->where('period_started_at', $periodStart->toDateTimeString())
            ->where('period_ended_at', $periodEnd->toDateTimeString())
            ->exists();
    }

    private function booleanSetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
