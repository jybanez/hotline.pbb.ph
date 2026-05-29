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
        return [
            'id' => $sitrep->id,
            'sequence_number' => $sitrep->sequence_number,
            'title' => $sitrep->title,
            'coverage_area' => $sitrep->coverage_area,
            'period_started_at' => $sitrep->period_started_at?->toIso8601String(),
            'period_ended_at' => $sitrep->period_ended_at?->toIso8601String(),
            'generated_at' => $sitrep->generated_at?->toIso8601String(),
            'published_at' => $sitrep->published_at?->toIso8601String(),
            'status' => $sitrep->status,
            'visibility' => $sitrep->visibility,
            'alert_level' => $sitrep->alert_level,
            'summary' => $sitrep->summary_json ?? [],
            'situation' => $sitrep->situation_json ?? [],
            'damage' => $sitrep->damage_json ?? [],
            'population' => $sitrep->population_json ?? [],
            'actions' => $sitrep->actions_json ?? [],
            'needs' => $sitrep->needs_json ?? [],
            'gaps' => $sitrep->gaps_json ?? [],
            'source_snapshot' => $sitrep->source_snapshot_json ?? [],
            'privacy_redactions' => $sitrep->privacy_redactions_json ?? [],
            'data_quality' => $sitrep->data_quality_json ?? [],
        ];
    }

    public function toJson(SitrepReport $sitrep): string
    {
        return json_encode($this->build($sitrep), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
