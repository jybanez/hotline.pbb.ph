<?php

namespace App\Support\Sitreps;

use App\Domain\Sitreps\Models\SitrepReport;

class SitrepExportPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(SitrepReport $sitrep): array
    {
        $sourceSnapshot = $sitrep->source_snapshot_json ?? [];
        $location = SitrepPayloadSchema::locationFromSourceSnapshot(SitrepPayloadSchema::rollup($sourceSnapshot));

        return [
            'schema_version' => SitrepPayloadSchema::VERSION,
            'id' => $sitrep->id,
            'sequence_number' => $sitrep->sequence_number,
            'title' => $sitrep->title,
            'coverage_area' => $sitrep->coverage_area,
            'location_count' => 1,
            'period_started_at' => $sitrep->period_started_at?->toIso8601String(),
            'period_ended_at' => $sitrep->period_ended_at?->toIso8601String(),
            'generated_at' => $sitrep->generated_at?->toIso8601String(),
            'published_at' => $sitrep->published_at?->toIso8601String(),
            'status' => $sitrep->status,
            'visibility' => $sitrep->visibility,
            'alert_level' => $sitrep->alert_level,
            'summary' => SitrepPayloadSchema::wrapSection($sitrep->summary_json ?? [], $location),
            'situation' => SitrepPayloadSchema::wrapSection($sitrep->situation_json ?? [], $location),
            'damage' => SitrepPayloadSchema::wrapSection($sitrep->damage_json ?? [], $location),
            'population' => SitrepPayloadSchema::wrapSection($sitrep->population_json ?? [], $location),
            'actions' => SitrepPayloadSchema::wrapSection($sitrep->actions_json ?? [], $location),
            'needs' => SitrepPayloadSchema::wrapSection($sitrep->needs_json ?? [], $location),
            'gaps' => SitrepPayloadSchema::wrapSection($sitrep->gaps_json ?? [], $location),
            'source_snapshot' => SitrepPayloadSchema::wrapSection($sourceSnapshot, $location),
            'privacy_redactions' => $sitrep->privacy_redactions_json ?? [],
            'data_quality' => SitrepPayloadSchema::wrapSection($sitrep->data_quality_json ?? [], $location),
        ];
    }

    public function toJson(SitrepReport $sitrep): string
    {
        return json_encode($this->build($sitrep), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
