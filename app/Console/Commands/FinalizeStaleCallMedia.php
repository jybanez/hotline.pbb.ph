<?php

namespace App\Console\Commands;

use App\Support\Media\MediaAssemblyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FinalizeStaleCallMedia extends Command
{
    protected $signature = 'app:finalize-stale-call-media {--grace-seconds=30 : Skip ended call sessions newer than this many seconds}';

    protected $description = 'Finalize recoverable processing call media for ended call sessions.';

    public function handle(MediaAssemblyService $mediaAssembly): int
    {
        $graceSeconds = max(0, (int) $this->option('grace-seconds'));
        $summary = $mediaAssembly->finalizeRecoverableProcessingAssets($graceSeconds);

        Log::info('Finalize stale call media cycle finished.', [
            'grace_seconds' => $graceSeconds,
            ...$summary,
        ]);

        $this->info(sprintf(
            'Finalize stale call media finished. scanned=%d finalized=%d skipped_no_chunks=%d skipped_not_ended=%d failed=%d',
            $summary['scanned'],
            $summary['finalized'],
            $summary['skipped_no_chunks'],
            $summary['skipped_not_ended'],
            $summary['failed'],
        ));

        foreach ($summary['failed_items'] as $failed) {
            $this->warn(sprintf(
                'media_id=%d failed: %s',
                (int) ($failed['media_id'] ?? 0),
                (string) ($failed['message'] ?? 'Unknown error')
            ));
        }

        return self::SUCCESS;
    }
}
