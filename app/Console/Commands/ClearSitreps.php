<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearSitreps extends Command
{
    protected $signature = 'app:clear-sitreps
        {--all : Delete all SITREPs}
        {--status= : Delete only SITREPs with this status: draft or published}
        {--force : Required to perform deletion}';

    protected $description = 'Clear SITREP reports and their Relay delivery rows.';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force.');

            return self::FAILURE;
        }

        $deleteAll = (bool) $this->option('all');
        $status = $this->normalizedStatus($this->option('status'));

        if (! $deleteAll) {
            $this->error('Provide --all. Optional --status=draft|published may be used to narrow the deletion.');

            return self::FAILURE;
        }

        if ($this->option('status') !== null && $status === null) {
            $this->error('Invalid --status value. Expected draft or published.');

            return self::FAILURE;
        }

        $query = DB::table('sitrep_reports')->orderBy('id');

        if ($status !== null) {
            $query->where('status', $status);
        }

        $reportIds = $query
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($reportIds === []) {
            $this->info('No matching SITREPs found.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Deleting %d SITREP report%s%s.',
            count($reportIds),
            count($reportIds) === 1 ? '' : 's',
            $status !== null ? " with status={$status}" : '',
        ));

        $summary = DB::transaction(function () use ($reportIds): array {
            return [
                'sitrep_relay_deliveries' => $this->deleteWhereIn('sitrep_relay_deliveries', 'sitrep_report_id', $reportIds),
                'sitrep_reports' => $this->deleteWhereIn('sitrep_reports', 'id', $reportIds),
            ];
        });

        foreach ($summary as $table => $count) {
            $this->line(sprintf('%s: %d', $table, $count));
        }

        $this->info('SITREP cleanup finished.');

        return self::SUCCESS;
    }

    private function normalizedStatus(mixed $status): ?string
    {
        $value = strtolower(trim((string) $status));

        return match ($value) {
            'draft', 'published' => $value,
            default => null,
        };
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteWhereIn(string $table, string $column, array $ids): int
    {
        if ($ids === [] || ! Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)
            ->whereIn($column, $ids)
            ->delete();
    }
}
