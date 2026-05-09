<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PruneDataApiCache extends Command
{
    protected $signature = 'app:prune-data-api-cache {--hours=168 : Delete stale cache files older than this many hours}';

    protected $description = 'Prune stale data API file cache entries from the dedicated file_data_api store';

    public function handle(): int
    {
        $cachePath = storage_path('framework/cache/data-api');
        $hours = max(1, (int) $this->option('hours'));
        $threshold = Carbon::now()->subHours($hours)->getTimestamp();

        Log::info('Data API cache prune started.', [
            'path' => $cachePath,
            'hours' => $hours,
            'threshold' => Carbon::createFromTimestamp($threshold)->toDateTimeString(),
        ]);

        if (!File::exists($cachePath)) {
            Log::info('Data API cache prune finished: cache path missing.', [
                'path' => $cachePath,
                'hours' => $hours,
            ]);
            $this->info("Data API cache path does not exist: {$cachePath}");
            return self::SUCCESS;
        }

        $deletedFiles = 0;
        $deletedBytes = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cachePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile()) {
                $mtime = $entry->getMTime();
                if ($mtime > $threshold) {
                    continue;
                }

                $deletedBytes += (int) $entry->getSize();
                File::delete($entry->getPathname());
                $deletedFiles++;
                continue;
            }

            if ($entry->isDir()) {
                $children = File::files($entry->getPathname());
                $directories = File::directories($entry->getPathname());
                if (!count($children) && !count($directories)) {
                    @rmdir($entry->getPathname());
                }
            }
        }

        $this->info(sprintf(
            'Pruned %d stale cache file(s), freed %s bytes, threshold %d hour(s).',
            $deletedFiles,
            number_format($deletedBytes),
            $hours
        ));

        Log::info('Data API cache prune finished.', [
            'path' => $cachePath,
            'hours' => $hours,
            'deleted_files' => $deletedFiles,
            'deleted_bytes' => $deletedBytes,
        ]);

        return self::SUCCESS;
    }
}
