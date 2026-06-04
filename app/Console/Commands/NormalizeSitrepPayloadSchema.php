<?php

namespace App\Console\Commands;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Support\Sitreps\SitrepPayloadSchema;
use Illuminate\Console\Command;

class NormalizeSitrepPayloadSchema extends Command
{
    protected $signature = 'app:normalize-sitrep-payload-schema {--dry-run : Report changes without updating rows}';

    protected $description = 'Normalize stored SITREP JSON sections to the current rollup/items payload schema.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;

        SitrepReport::query()
            ->orderBy('id')
            ->chunkById(100, function ($reports) use ($dryRun, &$changed): void {
                foreach ($reports as $report) {
                    $sourceSnapshot = $report->source_snapshot_json ?? [];
                    $sourceSnapshotRollup = SitrepPayloadSchema::withHubNodes(SitrepPayloadSchema::rollup($sourceSnapshot));
                    $location = SitrepPayloadSchema::locationFromSourceSnapshot($sourceSnapshotRollup);
                    $sourceSnapshotSection = isset($sourceSnapshot['items']) && is_array($sourceSnapshot['items'])
                        ? [
                            'rollup' => $sourceSnapshotRollup,
                            'items' => $sourceSnapshot['items'],
                        ]
                        : SitrepPayloadSchema::wrapSection($sourceSnapshotRollup, $location);
                    $updates = [
                        'summary_json' => SitrepPayloadSchema::wrapSection($report->summary_json ?? [], $location),
                        'situation_json' => SitrepPayloadSchema::wrapSection($report->situation_json ?? [], $location),
                        'damage_json' => SitrepPayloadSchema::wrapSection($report->damage_json ?? [], $location),
                        'population_json' => SitrepPayloadSchema::wrapSection($report->population_json ?? [], $location),
                        'actions_json' => SitrepPayloadSchema::wrapSection($report->actions_json ?? [], $location),
                        'needs_json' => SitrepPayloadSchema::wrapSection($report->needs_json ?? [], $location),
                        'gaps_json' => SitrepPayloadSchema::wrapSection($report->gaps_json ?? [], $location),
                        'source_snapshot_json' => $sourceSnapshotSection,
                        'data_quality_json' => SitrepPayloadSchema::wrapSection($report->data_quality_json ?? [], $location),
                    ];

                    $dirty = false;
                    foreach ($updates as $key => $value) {
                        if (($report->{$key} ?? null) !== $value) {
                            $dirty = true;
                            break;
                        }
                    }

                    if (! $dirty) {
                        continue;
                    }

                    $changed++;

                    if (! $dryRun) {
                        $report->forceFill($updates)->save();
                    }
                }
            });

        $this->info(sprintf(
            '%s %d SITREP report%s for payload schema v%d.',
            $dryRun ? 'Would normalize' : 'Normalized',
            $changed,
            $changed === 1 ? '' : 's',
            SitrepPayloadSchema::VERSION,
        ));

        return self::SUCCESS;
    }
}
