<?php

namespace App\Console\Commands;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Support\Settings\SettingsService;
use App\Support\Sitreps\PeriodicSitrepSchedule;
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

    public function handle(SettingsService $settings, SitrepGenerationService $sitreps, PeriodicSitrepSchedule $schedule): int
    {
        $forced = (bool) $this->option('force');

        if (! $forced && ! $schedule->isEnabled($settings)) {
            $this->info('Periodic SITREP generation is disabled.');

            return self::SUCCESS;
        }

        $window = $schedule->window($settings);
        $alertLevel = $window['alert_level'];
        $intervalMinutes = $window['interval_minutes'];
        $periodStart = $window['period_started_at'];
        $periodEnd = $window['period_ended_at'];

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

    private function reportExistsForWindow(Carbon $periodStart, Carbon $periodEnd): bool
    {
        return SitrepReport::query()
            ->where('period_started_at', $periodStart->toDateTimeString())
            ->where('period_ended_at', $periodEnd->toDateTimeString())
            ->exists();
    }
}
