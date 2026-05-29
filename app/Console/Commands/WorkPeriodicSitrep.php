<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WorkPeriodicSitrep extends Command
{
    protected $signature = 'app:work-periodic-sitrep
        {--sleep=60 : Seconds to wait between generation checks}
        {--max-runs=0 : Stop after this many checks; 0 runs until the process is stopped}';

    protected $description = 'Run the periodic SITREP generator loop as an isolated background worker.';

    public function handle(): int
    {
        $sleepSeconds = max(1, (int) $this->option('sleep'));
        $maxRuns = max(0, (int) $this->option('max-runs'));
        $shouldStop = false;

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, static function () use (&$shouldStop): void {
                    $shouldStop = true;
                });
            }
        }

        $this->info(sprintf(
            'Periodic SITREP worker started. Checking every %d seconds.',
            $sleepSeconds,
        ));

        $runs = 0;

        while (! $shouldStop) {
            $runs++;
            $exitCode = $this->call('app:generate-periodic-sitrep');

            if ($exitCode !== self::SUCCESS) {
                Log::warning('Periodic SITREP worker check failed.', [
                    'exit_code' => $exitCode,
                    'run' => $runs,
                ]);
            }

            if ($maxRuns > 0 && $runs >= $maxRuns) {
                break;
            }

            for ($elapsed = 0; $elapsed < $sleepSeconds && ! $shouldStop; $elapsed++) {
                sleep(1);
            }
        }

        $this->info('Periodic SITREP worker stopped.');

        return self::SUCCESS;
    }
}
