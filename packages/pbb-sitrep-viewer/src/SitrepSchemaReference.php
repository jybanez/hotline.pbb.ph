<?php

namespace Pbb\Sitreps\Viewer;

final class SitrepSchemaReference
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(): array
    {
        return [
            self::section('Envelope', 'Top-level SITREP metadata used by the document header and provenance.', [
                'schema_version',
                'title',
                'coverage_area',
                'period_started_at',
                'period_ended_at',
                'generated_at',
                'status',
                'visibility',
                'alert_level',
                'summary',
                'situation',
                'damage',
                'population',
                'actions',
                'needs',
                'gaps',
                'source_snapshot',
                'privacy_redactions',
                'data_quality',
            ], [
                'id',
                'sequence_number',
                'coverage_level',
                'location_count',
                'published_at',
                'reviewed_at',
            ]),
            self::wrappedSection('summary', 'Executive assessment, posture cards, decision points, and current totals.', [
                'headline',
                'posture',
                'posture_label',
                'primary_concern',
                'executive_cards[]',
                'supporting_metrics',
                'status_counts',
            ], [
                'posture_reason',
                'hotspot_area',
                'hotspot_note',
                'confidence_note',
                'gap_cards[]',
                'accomplishment_cards[]',
                'decision_points[]',
                'priority_watch_items[]',
                'current_operating_picture',
            ]),
            self::wrappedSection('situation', 'Current operating picture, grouped concerns, location distribution, and incident type distribution.', [
                'narrative',
                'locations[]',
                'incident_types[]',
            ], [
                'executive_assessment',
                'current_operating_picture',
                'concern_groups[]',
                'areas_of_concern[]',
                'notable_events[]',
                'decision_points[]',
                'period_activity',
                'verification_notes[]',
            ]),
            self::wrappedSection('damage', 'Reported damage summary and detailed damage facts.', [], [
                'damage_groups[]',
                'items[]',
                'historical_items[]',
                'empty_state',
                'confidence_note',
            ]),
            self::wrappedSection('population', 'Affected people, families, patient, evacuation, and vulnerable-sector signals.', [], [
                'citizens_assisted',
                'callers_assisted',
                'record_count',
                'numeric_total',
                'numeric_total_note',
                'population_groups[]',
                'items[]',
                'empty_state',
                'confidence_note',
            ]),
            self::wrappedSection('actions', 'Team deployment posture and assignment timing milestones.', [], [
                'total_assignments',
                'deployment_groups[]',
                'timing_rows[]',
                'confidence_note',
            ]),
            self::wrappedSection('needs', 'Resource demand by category and resource type.', [], [
                'total_quantity_requested',
                'category_groups[]',
                'items[]',
                'empty_state',
                'confidence_note',
            ]),
            self::wrappedSection('gaps', 'Operational constraints, confidence gaps, and supporting evidence rows.', [], [
                'title',
                'intro',
                'items[]',
                'empty_state',
            ]),
            self::wrappedSection('source_snapshot', 'Source and target provenance used for hub identity, drill-down, and map coordinates.', [
                'hub_node.snapshot.hub_id',
                'hub_node.snapshot.deployment',
                'hub_node.snapshot.name',
                'generation.type',
            ], [
                'hub_node.snapshot.relay_hub_id',
                'hub_nodes[]',
                'source_sitreps[]',
                'incident_ids[]',
                'incident_coordinates[]',
                'team_assignment_ids[]',
                'resource_need_ids[]',
                'incident_type_detail_ids[]',
                'hotline',
                'adapter_version',
                'counting_rule_version',
            ]),
            self::section('privacy_redactions', 'Flat redaction state inherited from the generated SITREP.', [], [
                'inherited',
                'note',
                'removed_fields[]',
                'masked_fields[]',
            ]),
            self::wrappedSection('data_quality', 'Quality notes, warnings, counting rules, and verification guidance.', [], [
                'global_note',
                'source_sitrep_count',
                'source_hub_count',
                'warnings[]',
                'period_activity',
                'verification_notes[]',
            ]),
        ];
    }

    public static function html(): string
    {
        $html = '<section class="sitrep-schema-reference" aria-label="SITREP payload reference">'
            .'<header><h2>SITREP Payload Reference</h2><p>Current schema v2 sections use <code>rollup</code> for rendered content and <code>items[]</code> for source/location drill-down. Legacy flat sections are still accepted by the viewer.</p></header>';

        foreach (self::sections() as $section) {
            $html .= '<details class="sitrep-schema-section">'
                .'<summary><strong>'.Html::text($section['name']).'</strong><span>'.Html::text($section['description']).'</span></summary>'
                .self::propertyGroup('Required', $section['required'])
                .self::propertyGroup('Optional / Rendered When Present', $section['optional'])
                .'</details>';
        }

        return $html.'</section>';
    }

    /**
     * @param array<int, string> $required
     * @param array<int, string> $optional
     * @return array{name: string, description: string, required: array<int, string>, optional: array<int, string>}
     */
    private static function section(string $name, string $description, array $required, array $optional): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'required' => $required,
            'optional' => $optional,
        ];
    }

    /**
     * @param array<int, string> $required
     * @param array<int, string> $optional
     * @return array{name: string, description: string, required: array<int, string>, optional: array<int, string>}
     */
    private static function wrappedSection(string $name, string $description, array $required, array $optional): array
    {
        return self::section($name, $description, array_values(array_unique(array_merge(['rollup', 'items[]'], $required))), $optional);
    }

    /**
     * @param array<int, string> $properties
     */
    private static function propertyGroup(string $label, array $properties): string
    {
        if ($properties === []) {
            return '<div class="sitrep-schema-group"><h3>'.Html::text($label).'</h3><p class="sitrep-schema-empty">None.</p></div>';
        }

        $items = array_map(static fn (string $property): string => '<li><code>'.Html::text($property).'</code></li>', $properties);

        return '<div class="sitrep-schema-group"><h3>'.Html::text($label).'</h3><ul>'.implode('', $items).'</ul></div>';
    }
}
