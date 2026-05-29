<?php

namespace App\Console\Commands;

use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Domain\Users\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\Sitreps\SitrepGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GeneratePeriodicSitrep extends Command
{
    protected $signature = 'app:generate-periodic-sitrep
        {--force : Generate even when disabled or when the window already has a report}
        {--dry-run : Show the computed reporting window without creating a report}
        {--prepared-by-user-id= : Command user id to attribute as prepared by}
        {--coverage-area= : Coverage area override for the generated SITREP}';

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

        $preparedBy = $this->preparedByUser($settings);

        if (! $preparedBy) {
            $this->error('No active command user is available to prepare the periodic SITREP.');

            return self::FAILURE;
        }

        $coverageArea = $this->coverageArea($settings);
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
        ];

        if ($coverageArea !== '') {
            $payload['coverage_area'] = $coverageArea;
        }

        if ((bool) $this->option('dry-run')) {
            $this->line(sprintf('Alert level: %s', $alertLevel->value));
            $this->line(sprintf('Interval minutes: %d', $intervalMinutes));
            $this->line(sprintf('Period start: %s', $periodStart->toIso8601String()));
            $this->line(sprintf('Period end: %s', $periodEnd->toIso8601String()));
            $this->line(sprintf('Prepared by: %s <%s>', $preparedBy->name, $preparedBy->email));
            $this->line(sprintf('Coverage area: %s', $coverageArea !== '' ? $coverageArea : 'PBB Hotline Coverage Area'));

            return self::SUCCESS;
        }

        $report = $sitreps->generate($preparedBy, $payload);

        Log::info('Periodic SITREP generated.', [
            'sitrep_id' => $report->id,
            'sequence_number' => $report->sequence_number,
            'alert_level' => $alertLevel->value,
            'interval_minutes' => $intervalMinutes,
            'period_started_at' => $periodStart->toIso8601String(),
            'period_ended_at' => $periodEnd->toIso8601String(),
            'prepared_by_user_id' => $preparedBy->id,
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

    private function preparedByUser(SettingsService $settings): ?User
    {
        $optionUserId = $this->option('prepared-by-user-id');
        $settingUserId = $settings->get('sitrep_periodic_prepared_by_user_id');
        $userId = $optionUserId !== null && $optionUserId !== ''
            ? (int) $optionUserId
            : (int) ($settingUserId ?: 0);

        $query = User::query()
            ->where('role', UserRole::Command)
            ->where('status', UserStatus::Active);

        if ($userId > 0) {
            return (clone $query)->whereKey($userId)->first();
        }

        return $query->orderBy('id')->first();
    }

    private function coverageArea(SettingsService $settings): string
    {
        $optionCoverageArea = $this->option('coverage-area');

        if (is_string($optionCoverageArea) && trim($optionCoverageArea) !== '') {
            return trim($optionCoverageArea);
        }

        return trim((string) $settings->get('sitrep_periodic_coverage_area', ''));
    }

    private function booleanSetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
