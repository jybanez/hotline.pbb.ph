<?php

namespace Pbb\Sitreps\Viewer;

final class SitrepDocumentRenderer
{
    private string $layout = 'document';

    /**
     * @return array<int, string>
     */
    public static function sectionNames(): array
    {
        return [
            'header',
            'summary',
            'situation',
            'damage',
            'population',
            'actions',
            'needs',
            'gaps',
            'period_activity',
            'verification_notes',
            'footer',
        ];
    }

    public function render(SitrepPayload $sitrep, SitrepViewOptions $options): string
    {
        $this->layout = $options->layout;
        $summary = $sitrep->section('summary');
        $situation = $sitrep->section('situation');
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $identity = $this->identity($sitrep);
        $showLocations = (int) $sitrep->get('location_count', 1) > 1;
        $classes = ['pbb-sitrep-viewer', 'sitrep-page'];
        if ($options->preview) {
            $classes[] = 'is-preview';
        }
        if ($options->pdf) {
            $classes[] = 'is-pdf';
        }
        if ($options->layout !== 'document') {
            $classes[] = 'is-layout-'.$options->layout;
        }
        if ($sitrep->get('status') === 'draft') {
            $classes[] = 'is-draft';
        }

        return '<main class="'.Html::escape(implode(' ', $classes)).'">'
            .'<article class="sitrep-document">'
            .$this->previewBanner($sitrep, $options)
            .$this->header($sitrep, $summary, $sourceSnapshot, $identity)
            .$this->summary($summary, $situation, $sourceSnapshot)
            .$this->situation($situation)
            .$this->damage($sitrep->section('damage'))
            .$this->population($sitrep->section('population'), $showLocations)
            .$this->actions($sitrep->section('actions'))
            .$this->needs($sitrep->section('needs'), $showLocations)
            .$this->gaps($sitrep->section('gaps'), $sourceSnapshot, $sitrep->section('needs'), $sitrep->section('population'))
            .$this->periodActivity($situation)
            .$this->verificationNotes($situation)
            .$this->footer($sitrep)
            .'</article>'
            .'</main>';
    }

    public function renderSection(SitrepPayload $sitrep, string $section, ?SitrepViewOptions $options = null): string
    {
        $options ??= new SitrepViewOptions();
        $previousLayout = $this->layout;
        $this->layout = $options->layout;
        $section = strtolower(trim(str_replace('-', '_', $section)));
        $summary = $sitrep->section('summary');
        $situation = $sitrep->section('situation');
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $showLocations = (int) $sitrep->get('location_count', 1) > 1;

        $html = match ($section) {
            'header' => $this->header($sitrep, $summary, $sourceSnapshot, $this->identity($sitrep)),
            'summary' => $this->summary($summary, $situation, $sourceSnapshot),
            'situation' => $this->situation($situation),
            'damage' => $this->damage($sitrep->section('damage')),
            'population' => $this->population($sitrep->section('population'), $showLocations),
            'actions' => $this->actions($sitrep->section('actions')),
            'needs' => $this->needs($sitrep->section('needs'), $showLocations),
            'gaps' => $this->gaps($sitrep->section('gaps'), $sourceSnapshot, $sitrep->section('needs'), $sitrep->section('population')),
            'period_activity', 'period_activity_report' => $this->periodActivity($situation),
            'verification_notes', 'verification' => $this->verificationNotes($situation),
            'footer', 'source_snapshot', 'data_quality' => $this->footer($sitrep),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown SITREP section [%s]. Supported sections: %s.',
                $section,
                implode(', ', self::sectionNames()),
            )),
        };

        $this->layout = $previousLayout;

        if ($options->layout === 'document') {
            return $html;
        }

        return '<div class="pbb-sitrep-viewer sitrep-section-fragment is-layout-'.Html::escape($options->layout).'">'.$html.'</div>';
    }

    /**
     * @param array<int, string> $sections
     */
    public function renderSections(SitrepPayload $sitrep, array $sections, ?SitrepViewOptions $options = null): string
    {
        $options ??= new SitrepViewOptions();
        $html = implode('', array_map(
            fn (string $section): string => $this->renderSection($sitrep, $section, $options),
            $sections,
        ));

        if ($options->layout === 'document') {
            return $html;
        }

        return '<div class="pbb-sitrep-viewer sitrep-section-fragment is-layout-'.Html::escape($options->layout).'">'.$html.'</div>';
    }

    private function previewBanner(SitrepPayload $sitrep, SitrepViewOptions $options): string
    {
        if (! $options->preview || ($sitrep->get('status') === 'published' && $sitrep->get('visibility') === 'public')) {
            return '';
        }

        return '<div class="sitrep-preview-banner">Preview only. This SITREP is not public unless status is published and visibility is public.</div>';
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $sourceSnapshot
     * @param array<string, mixed> $identity
     */
    private function header(SitrepPayload $sitrep, array $summary, array $sourceSnapshot, array $identity): string
    {
        $generation = is_array($sourceSnapshot['generation'] ?? null) ? $sourceSnapshot['generation'] : [];
        $preparedBy = trim((string) ($generation['prepared_by_label'] ?? '')) ?: 'System Generated';
        $meta = $this->inlineParts([
            '#'.str_pad((string) ($sitrep->get('sequence_number') ?? ''), 4, '0', STR_PAD_LEFT),
            ucfirst((string) $sitrep->get('status')).' / '.ucfirst((string) $sitrep->get('visibility')),
            $sitrep->get('alert_level', 'Normal'),
            $preparedBy,
            $this->formatDate($sitrep->get('generated_at')),
        ]);

        return '<header class="sitrep-header">'
            .'<div>'
            .'<p class="sitrep-eyebrow">PBB Hotline Periodic SITREP</p>'
            .'<h1>'.Html::text($identity['title'] ?? $sitrep->get('title')).'</h1>'
            .'<p class="sitrep-periodline">'.Html::joined([$identity['hub'] ?? null, $identity['period'] ?? null]).'</p>'
            .'<p class="sitrep-headline">'.Html::text($summary['headline'] ?? 'Situation report generated from Hotline incident records.').'</p>'
            .'</div>'
            .'<p class="sitrep-metaline">'.$meta.'</p>'
            .'</header>';
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $situation
     * @param array<string, mixed> $sourceSnapshot
     */
    private function summary(array $summary, array $situation, array $sourceSnapshot): string
    {
        $targetName = $this->targetName($sourceSnapshot);
        $html = '<section class="sitrep-section sitrep-summary">'
            .$this->sectionHead('Summary', 'Executive Situation Assessment')
            .'<p class="sitrep-narrative">'.Html::text($situation['executive_assessment'] ?? $situation['narrative'] ?? 'No executive assessment is available.').'</p>';

        if (! empty($summary['gap_cards']) && is_array($summary['gap_cards'])) {
            $html .= $this->cardRow('Gaps', $summary['gap_cards'], false, $targetName);
        }

        if (! empty($summary['accomplishment_cards']) && is_array($summary['accomplishment_cards'])) {
            $html .= $this->cardRow('Accomplishments', $summary['accomplishment_cards'], true, $targetName);
        } elseif (! empty($summary['executive_cards']) && is_array($summary['executive_cards'])) {
            $html .= $this->pictureGrid($summary['executive_cards'], $targetName);
        } else {
            $html .= $this->pictureGrid([
                [
                    'label' => 'Operational Posture',
                    'value' => $summary['posture_label'] ?? ucfirst((string) ($summary['posture'] ?? 'Monitoring')),
                    'note' => $summary['posture_reason'] ?? 'Posture is based on active incidents, assignments, needs, and data-quality signals.',
                ],
                [
                    'label' => 'Primary Concern',
                    'value' => $summary['primary_concern'] ?? 'No primary concern identified.',
                    'note' => $summary['confidence_note'] ?? 'Generated from available incident records.',
                ],
                [
                    'label' => 'Current Areas',
                    'value' => $summary['hotspot_area'] ?? 'No hotspot identified',
                    'note' => $summary['hotspot_note'] ?? 'Dominant type: '.(string) ($summary['dominant_incident_type'] ?? 'Unclassified'),
                ],
            ], $targetName);
        }

        if (! empty($situation['decision_points']) && is_array($situation['decision_points'])) {
            $html .= '<div class="sitrep-watch"><h3>Decision Points</h3>';
            foreach ($situation['decision_points'] as $point) {
                if (! is_array($point)) {
                    continue;
                }
                $html .= '<p><strong>'.Html::text($point['title'] ?? 'Decision point').':</strong> '.Html::text($point['body'] ?? '').'</p>';
            }
            $html .= '</div>';
        }

        if (! empty($situation['current_operating_picture']) && is_array($situation['current_operating_picture'])) {
            $picture = $situation['current_operating_picture'];
            $html .= '<p class="sitrep-source-counts"><strong>Current totals:</strong> '
                .$this->inlineParts([
                    Html::number($picture['open_reports'] ?? 0).' open reports',
                    Html::number($picture['active_reports'] ?? 0).' active',
                    Html::number($picture['deferred_reports'] ?? 0).' deferred',
                    Html::number($picture['current_assignments'] ?? 0).' assignments',
                    Html::number($picture['current_resource_units'] ?? 0).' requested resource units',
                ])
                .'</p>';
        }

        return $html.'</section>';
    }

    /**
     * @param array<string, mixed> $situation
     */
    private function situation(array $situation): string
    {
        $html = '<section class="sitrep-section">'
            .$this->sectionHead('Situation', 'Current Areas of Concern')
            .'<p class="sitrep-narrative">'.Html::text($situation['narrative'] ?? 'No situation narrative is available.').'</p>';

        if (! empty($situation['concern_groups']) && is_array($situation['concern_groups'])) {
            $rows = array_map(fn (array $row): array => [
                $row['concern'] ?? 'Current concern',
                $row['open_reports'] ?? 0,
                implode(', ', $row['areas'] ?? []),
                $row['main_signals'] ?? '',
                $row['current_assignments'] ?? 0,
                $row['resource_units'] ?? 0,
            ], array_filter($situation['concern_groups'], 'is_array'));
            $html .= $this->table('Grouped Current Concerns', ['Concern', 'Open Reports', 'Areas', 'Main Signals', 'Teams', 'Resources'], $rows, 'No grouped current concerns available.', 'is-concern-groups');
            $html .= '<p class="sitrep-note">Individual incident references are retained in the source snapshot and supporting tables.</p>';
        } elseif (! empty($situation['areas_of_concern']) && is_array($situation['areas_of_concern'])) {
            $html .= '<div class="sitrep-fact-list">';
            foreach ($situation['areas_of_concern'] as $area) {
                if (! is_array($area)) {
                    continue;
                }
                $html .= '<article class="sitrep-fact"><span>#'.Html::text(str_pad((string) ($area['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT)).' · '.Html::text($area['status'] ?? 'Open').'</span>'
                    .'<strong>'.Html::text($area['area'] ?? 'Area of concern').'</strong>'
                    .'<p>'.Html::text($area['summary'] ?? '').'</p></article>';
            }
            $html .= '</div>';
        }

        $locationRows = array_map(fn (array $row): array => [
            $row['area'] ?? 'Unknown',
            $row['alert_level'] ?? '',
            $row['count'] ?? $row['report_count'] ?? 0,
        ], array_filter($situation['locations'] ?? [], 'is_array'));
        $typeRows = array_map(fn (array $row): array => [
            $row['type'] ?? 'Unclassified',
            $row['location_count'] ?? count(array_unique(array_filter((array) ($row['source_hubs'] ?? []), 'is_scalar'))),
            $row['count'] ?? 0,
        ], array_filter($situation['incident_types'] ?? [], 'is_array'));

        return $html.'<div class="sitrep-two-column">'
            .$this->table('Current Locations', ['Area', 'Alert Level', 'Incidents'], $locationRows, 'No location distribution available.')
            .$this->table('Current Incident Types', ['Type', 'Locations', 'Mentions'], $typeRows, 'No incident type distribution available.')
            .'</div></section>';
    }

    /**
     * @param array<string, mixed> $damage
     */
    private function damage(array $damage): string
    {
        $html = '<section class="sitrep-section">'.$this->sectionHead('Damage', 'Reported Damage');

        if (! empty($damage['damage_groups']) && is_array($damage['damage_groups'])) {
            $rows = array_map(fn (array $row): array => [
                $row['damage_type'] ?? 'Reported damage',
                $row['reports'] ?? 0,
                $row['severity_signal'] ?? '',
                $row['affected_assets'] ?? '',
            ], array_filter($damage['damage_groups'], 'is_array'));
            $html .= $this->table('Damage Summary', ['Damage Type', 'Reports', 'Severity / Signal', 'Affected Assets'], $rows, (string) ($damage['empty_state'] ?? 'No damage entries available.'));
            $html .= '<p class="sitrep-note">Individual damage entries are retained in the source snapshot and exports.</p>';
        } else {
            $html .= $this->factList($damage['items'] ?? [], (string) ($damage['empty_state'] ?? 'No damage entries available.'));
        }

        return $html.'<p class="sitrep-note">'.Html::text($damage['confidence_note'] ?? '').'</p></section>';
    }

    /**
     * @param array<string, mixed> $population
     */
    private function population(array $population, bool $showLocations): string
    {
        $html = '<section class="sitrep-section">'.$this->sectionHead('Population', 'Affected People')
            .'<div class="sitrep-metrics is-compact">'
            .$this->metric('People at Risk', $population['people_at_risk'] ?? $population['numeric_total'] ?? 0)
            .$this->metric('People Helped', $population['citizens_assisted'] ?? $population['callers_assisted'] ?? 0, 'is-positive')
            .$this->metric('Current Records', $population['record_count'] ?? count($population['items'] ?? []))
            .'</div>';

        if (! empty($population['numeric_total_note'])) {
            $html .= '<p class="sitrep-note">'.Html::text($population['numeric_total_note']).'</p>';
        }

        if (! empty($population['population_groups']) && is_array($population['population_groups'])) {
            $headers = $showLocations
                ? ['Population Signal', 'Locations', 'Reports', 'People / Families', 'Notes']
                : ['Population Signal', 'Reports', 'People / Families', 'Notes'];
            $rows = array_map(fn (array $row): array => $showLocations ? [
                    $row['population_signal'] ?? 'Population signal',
                    $this->locationCount($row),
                    $row['reports'] ?? 0,
                    $row['people_or_families'] ?? $row['people_families'] ?? '',
                    $row['notes'] ?? '',
                ] : [
                    $row['population_signal'] ?? 'Population signal',
                    $row['reports'] ?? 0,
                    $row['people_or_families'] ?? $row['people_families'] ?? '',
                    $row['notes'] ?? '',
                ], array_filter($population['population_groups'], 'is_array'));
            $html .= $this->table('Population Summary', $headers, $rows, (string) ($population['empty_state'] ?? 'No population entries available.'));

            $breakdownRows = [];
            foreach ($population['population_groups'] as $group) {
                if (! is_array($group)) {
                    continue;
                }
                foreach (($group['breakdowns'] ?? []) as $row) {
                    if (is_array($row)) {
                        $breakdownRows[] = $showLocations ? [
                            $group['population_signal'] ?? 'Population signal',
                            $row['breakdown'] ?? 'Breakdown',
                            $this->locationCount($row),
                            $row['count'] ?? 0,
                        ] : [
                            $group['population_signal'] ?? 'Population signal',
                            $row['breakdown'] ?? 'Breakdown',
                            $row['count'] ?? 0,
                        ];
                    }
                }
            }
            if ($breakdownRows !== []) {
                $headers = $showLocations
                    ? ['Population Signal', 'Breakdown', 'Locations', 'Count']
                    : ['Population Signal', 'Breakdown', 'Count'];
                $html .= $this->table('Declared Member Breakdown', $headers, $breakdownRows, 'No member breakdowns declared.');
            }
            $html .= '<p class="sitrep-note">Individual population entries are retained in the source snapshot and exports.</p>';
        } else {
            $html .= $this->factList($population['items'] ?? [], (string) ($population['empty_state'] ?? 'No population entries available.'));
        }

        return $html.'<p class="sitrep-note">'.Html::text($population['confidence_note'] ?? '').'</p></section>';
    }

    /**
     * @param array<string, mixed> $actions
     */
    private function actions(array $actions): string
    {
        $deploymentRows = array_map(function (array $row): array {
            $counts = is_array($row['status_counts'] ?? null) ? $row['status_counts'] : [];

            return [
                $row['category'] ?? 'Uncategorized',
                $row['team'] ?? 'Team',
                ($counts['requested'] ?? 0) ?: '',
                ($counts['assigned'] ?? 0) ?: '',
                ($counts['accepted'] ?? 0) ?: '',
                ($counts['en_route'] ?? 0) ?: '',
                ($counts['on_scene'] ?? 0) ?: '',
            ];
        }, array_filter($actions['deployment_groups'] ?? [], 'is_array'));

        $timingRows = array_map(fn (array $row): array => [
            '#'.str_pad((string) ($row['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT),
            $row['team'] ?? 'Team',
            $row['current_status'] ?? '',
            $row['assigned_to_accepted'] ?? '',
            $row['accepted_to_en_route'] ?? '',
            $row['en_route_to_on_scene'] ?? '',
            $row['elapsed_time'] ?? '',
        ], array_filter($actions['timing_rows'] ?? [], 'is_array'));

        return '<section class="sitrep-section">'
            .$this->sectionHead('Actions', 'Response Posture')
            .$this->table('Team Deployment', ['Category', 'Team', 'Requested', 'Assigned', 'Accepted', 'En Route', 'On Scene'], $deploymentRows, 'No team assignments recorded.', 'is-team-deployment')
            .$this->table('Assignment Timing', ['Incident', 'Team', 'Status', 'Accepted', 'En Route', 'On Scene', 'Time in Status'], $timingRows, 'No assignment timing milestones recorded.', 'is-assignment-timing')
            .'<p class="sitrep-note">Timing rows are scenario-specific and derived from team assignment milestone timestamps. Time in Status shows how long an open assignment had been in its current status as of report generation, falling back to assignment time when older records do not have the milestone timestamp.</p>'
            .'</section>';
    }

    /**
     * @param array<string, mixed> $needs
     */
    private function needs(array $needs, bool $showLocations): string
    {
        $html = '<section class="sitrep-section">'.$this->sectionHead('Needs', 'Current Resource Posture');

        if (! empty($needs['category_groups']) && is_array($needs['category_groups'])) {
            $headers = $showLocations
                ? ['Category', 'Locations', 'Quantity', 'Resources']
                : ['Category', 'Quantity', 'Resources'];
            $rows = array_map(fn (array $row): array => $showLocations ? [
                    $row['category'] ?? 'Uncategorized',
                    $this->locationCount($row),
                    $row['quantity_requested'] ?? 0,
                    implode(', ', $row['resources'] ?? []),
                ] : [
                    $row['category'] ?? 'Uncategorized',
                    $row['quantity_requested'] ?? 0,
                    implode(', ', $row['resources'] ?? []),
                ], array_filter($needs['category_groups'], 'is_array'));
            $html .= $this->table('Category Demand', $headers, $rows, 'No category demand available.', 'is-category-demand');
        }

        $headers = $showLocations
            ? ['Resource', 'Category', 'Locations', 'Quantity', 'Incidents']
            : ['Resource', 'Category', 'Quantity', 'Incidents'];
        $rows = array_map(fn (array $row): array => $showLocations ? [
                $row['resource'] ?? 'Resource',
                $row['category'] ?? 'Uncategorized',
                $this->locationCount($row),
                $row['quantity_requested'] ?? 0,
                $row['incident_count'] ?? 0,
            ] : [
                $row['resource'] ?? 'Resource',
                $row['category'] ?? 'Uncategorized',
                $row['quantity_requested'] ?? 0,
                $row['incident_count'] ?? 0,
            ], array_filter($needs['items'] ?? [], 'is_array'));

        return $html
            .$this->table('Resource Needs', $headers, $rows, (string) ($needs['empty_state'] ?? 'No structured resource needs recorded.'), 'is-resource-needs')
            .'<p class="sitrep-note">'.Html::text($needs['confidence_note'] ?? '').'</p></section>';
    }

    /**
     * @param array<string, mixed> $gaps
     */
    private function gaps(array $gaps, array $sourceSnapshot, array $needs, array $population): string
    {
        $targetName = $this->targetName($sourceSnapshot);
        $html = '<section class="sitrep-section">'.$this->sectionHead('Gaps', (string) ($gaps['title'] ?? 'Response Constraints and Confidence Gaps'));
        if (! empty($gaps['intro'])) {
            $html .= '<p class="sitrep-narrative">'.Html::text($gaps['intro']).'</p>';
        }

        $items = array_filter($gaps['items'] ?? [], fn (mixed $item): bool => is_array($item) && ! $this->isCountingScopeGap($item));
        if ($items === []) {
            return $html.'<p class="sitrep-empty">'.Html::text($gaps['empty_state'] ?? 'No gaps identified.').'</p></section>';
        }

        foreach ($items as $gap) {
            $html .= '<article class="sitrep-gap">';
            if (! empty($gap['category'])) {
                $html .= '<span>'.Html::text($gap['category']).'</span>';
            }
            $html .= '<strong>'.Html::text($gap['title'] ?? 'Gap').'</strong>';
            $body = $gap['decision_relevance'] ?? $gap['body'] ?? '';
            if ($body !== '') {
                $html .= '<p>'.Html::text($body).'</p>';
            }
            $evidence = $this->gapEvidence($gap, $targetName, $needs, $population);
            if ($evidence !== '' || ! empty($gap['confidence_note'])) {
                $html .= '<dl class="sitrep-gap-details">';
                if ($evidence !== '') {
                    $html .= '<div><dt>Evidence</dt><dd>'.$evidence.'</dd></div>';
                }
                if (! empty($gap['confidence_note'])) {
                    $html .= '<div><dt>Confidence</dt><dd>'.Html::text($gap['confidence_note']).'</dd></div>';
                }
                $html .= '</dl>';
            }
            $html .= '</article>';
        }

        return $html.'</section>';
    }

    /**
     * @param array<string, mixed> $gap
     */
    private function gapEvidence(array $gap, ?string $targetName, array $needs, array $population): string
    {
        $evidence = trim((string) ($gap['evidence'] ?? ''));
        $sourceHubs = array_values(array_filter((array) ($gap['source_hubs'] ?? []), 'is_scalar'));
        if ($this->isResourceSupplyGap($gap)) {
            $groups = $this->resourceEvidenceGroups($gap, $needs, $targetName);
            if ($groups !== []) {
                return $this->resourceEvidenceCards($groups);
            }
        }

        if ($this->isPopulationConfidenceGap($gap)) {
            $groups = $this->populationEvidenceGroups($gap, $population, $targetName);
            if ($groups !== []) {
                return $this->populationEvidenceCards($groups);
            }

            $groups = $this->populationEvidenceGroupsFromEvidence($evidence, $sourceHubs, $targetName);
            if ($groups !== []) {
                return $this->populationEvidenceCards($groups);
            }
        }

        $routeGroups = $this->routeEvidenceGroups($gap, $targetName);
        if ($routeGroups !== []) {
            return $this->routeEvidenceCards($routeGroups);
        }

        if ($evidence === '' || $sourceHubs === []) {
            return Html::text($evidence);
        }

        $rows = [];
        foreach ($sourceHubs as $index => $sourceHub) {
            $source = (string) $sourceHub;
            $start = strpos($evidence, $source.':');
            if ($start === false) {
                continue;
            }

            $start += strlen($source) + 1;
            $end = strlen($evidence);
            foreach (array_slice($sourceHubs, $index + 1) as $nextSourceHub) {
                $next = strpos($evidence, (string) $nextSourceHub.':', $start);
                if ($next !== false) {
                    $end = $next;
                    break;
                }
            }

            $text = trim(substr($evidence, $start, $end - $start));
            $text = preg_replace('/\s+$/', '', rtrim($text, " \t\n\r\0\x0B.")) ?? $text;
            if ($text !== '') {
                $rows[] = [$this->shortLocation($source, $targetName), $text];
            }
        }

        if ($rows === []) {
            return Html::text($evidence);
        }

        $populationRows = $this->populationEvidenceRows($rows);
        if ($populationRows !== []) {
            return $this->table('Population Evidence', ['Location', 'People', 'Breakdown'], $populationRows, 'No population evidence reported.');
        }

        $resourceRows = $this->resourceEvidenceRowsFromSourceRows($rows);
        if ($resourceRows !== []) {
            return $this->resourceEvidenceCards(array_map(fn (array $row): array => [
                'location' => $row[0],
                'headers' => ['Units', 'Note'],
                'rows' => [[$row[1], $row[2]]],
            ], $resourceRows));
        }

        return $this->propertyList(['Location', 'Evidence'], $rows);
    }

    /**
     * @param array<string, mixed> $gap
     */
    private function isResourceSupplyGap(array $gap): bool
    {
        $type = strtolower(trim((string) ($gap['type'] ?? '')));
        $title = strtolower(trim((string) ($gap['title'] ?? '')));

        return $type === 'open_needs' || str_contains($title, 'resource supply');
    }

    /**
     * @param array<string, mixed> $gap
     */
    private function isPopulationConfidenceGap(array $gap): bool
    {
        $category = strtolower(trim((string) ($gap['category'] ?? '')));
        $title = strtolower(trim((string) ($gap['title'] ?? '')));

        return str_contains($category, 'data confidence') && str_contains($title, 'population');
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private function populationEvidenceRows(array $rows): array
    {
        $populationRows = [];
        foreach ($rows as $row) {
            $location = trim((string) ($row[0] ?? ''));
            $evidence = trim((string) ($row[1] ?? ''));
            if (! preg_match('/^(\d+)\s+current\s+population\/life-safety\s+records?\s+reported:\s*(.+)$/i', $evidence, $matches)) {
                return [];
            }

            $populationRows[] = [$location, $matches[1], trim($matches[2])];
        }

        return $populationRows;
    }

    /**
     * @param array<int, mixed> $sourceHubs
     * @return array<int, array{location: string, rows: array<int, array<int, mixed>>}>
     */
    private function populationEvidenceGroupsFromEvidence(string $evidence, array $sourceHubs, ?string $targetName): array
    {
        if (! preg_match('/^(\d+)\s+current\s+population\/life-safety\s+records?\s+reported:\s*(.+)$/i', trim($evidence), $matches)) {
            return [];
        }

        $location = 'Current Location';
        if (count($sourceHubs) === 1) {
            $location = $this->shortLocation((string) $sourceHubs[0], $targetName);
        } elseif (trim((string) $targetName) !== '') {
            $location = $this->shortLocation((string) $targetName, null);
        }

        return [[
            'location' => $location,
            'rows' => [[
                'Population/life-safety records',
                (int) $matches[1],
                (int) $matches[1],
                trim($matches[2]),
            ]],
        ]];
    }

    /**
     * @param array<string, mixed> $gap
     * @param array<string, mixed> $population
     * @return array<int, array{location: string, rows: array<int, array<int, mixed>>}>
     */
    private function populationEvidenceGroups(array $gap, array $population, ?string $targetName): array
    {
        $sourceHubs = array_values(array_filter((array) ($gap['source_hubs'] ?? []), 'is_scalar'));
        $allowedLocations = $sourceHubs === []
            ? null
            : array_fill_keys(array_map(fn (mixed $source): string => $this->shortLocation((string) $source, $targetName), $sourceHubs), true);
        $groups = [];
        foreach (array_filter($population['population_groups'] ?? [], 'is_array') as $populationGroup) {
            $signal = trim((string) ($populationGroup['population_signal'] ?? 'Population signal'));
            $notes = trim((string) ($populationGroup['notes'] ?? ''));
            $sourceValues = array_values(array_filter($populationGroup['source_values'] ?? [], 'is_array'));
            $sources = $sourceValues === [] ? [[
                'source_hub_name' => trim((string) ($targetName ?? '')) !== '' ? $targetName : 'Current Location',
                'reports' => $populationGroup['reports'] ?? null,
                'people_or_families' => $populationGroup['people_or_families'] ?? null,
            ]] : $sourceValues;
            foreach ($sources as $source) {
                $sourceName = trim((string) ($source['source_hub_name'] ?? ($targetName ?? 'Current Location')));
                if ($sourceName === '') {
                    continue;
                }

                $location = $this->shortLocation($sourceName, $targetName);
                if ($allowedLocations !== null && ! isset($allowedLocations[$location])) {
                    continue;
                }

                $reports = (int) ($source['reports'] ?? 0);
                $peopleOrFamilies = trim((string) ($source['people_or_families'] ?? ''));
                $people = $this->populationPeopleValue($peopleOrFamilies, $reports);
                $notesText = $this->populationEvidenceNotes($notes, $peopleOrFamilies, $populationGroup);
                $groups[$location] ??= [
                    'location' => $location,
                    'rows' => [],
                ];
                $groups[$location]['rows'][] = [
                    $signal,
                    $reports,
                    $people,
                    $notesText,
                ];
            }
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group['rows'] !== []));
    }

    private function populationPeopleValue(string $peopleOrFamilies, int $fallback): int
    {
        if (preg_match('/(\d+)\s+people\b/i', $peopleOrFamilies, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)\s+persons?\b/i', $peopleOrFamilies, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)\s+records?\b/i', $peopleOrFamilies, $matches)) {
            return (int) $matches[1];
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $populationGroup
     */
    private function populationEvidenceNotes(string $notes, string $peopleOrFamilies, array $populationGroup): string
    {
        $parts = [];
        if (preg_match('/(\d+)\s+famil(?:y|ies)\b/i', $peopleOrFamilies, $matches)) {
            $familyCount = (int) $matches[1];
            $parts[] = $familyCount.' '.($familyCount === 1 ? 'family' : 'families');
        }

        $noteSummary = $this->compactNoteList($notes);
        if ($noteSummary !== '') {
            $parts[] = $noteSummary;
        }

        $breakdown = $this->populationBreakdownNote($populationGroup);
        if ($breakdown !== '') {
            $parts[] = $breakdown;
        }

        return implode('; ', array_values(array_unique($parts)));
    }

    private function compactNoteList(string $notes): string
    {
        $notes = trim($notes);
        if ($notes === '') {
            return '';
        }

        $fragments = preg_split('/\s*;\s*/', $notes) ?: [];
        $unique = [];
        foreach ($fragments as $fragment) {
            $fragment = trim($fragment);
            if ($fragment === '') {
                continue;
            }

            $key = strtolower($fragment);
            $unique[$key] ??= $fragment;
        }

        return implode('; ', array_values($unique));
    }

    /**
     * @param array<string, mixed> $populationGroup
     */
    private function populationBreakdownNote(array $populationGroup): string
    {
        $breakdowns = [];
        foreach (array_filter($populationGroup['breakdowns'] ?? [], 'is_array') as $breakdown) {
            $label = trim((string) ($breakdown['breakdown'] ?? ''));
            $count = (int) ($breakdown['count'] ?? 0);
            if ($label !== '' && $count > 0) {
                $breakdowns[] = $count.' '.$label;
            }
        }

        return $breakdowns === [] ? '' : 'Overall declared breakdown: '.implode(', ', $breakdowns);
    }

    /**
     * @param array<int, array{location: string, rows: array<int, array<int, mixed>>}> $groups
     */
    private function populationEvidenceCards(array $groups): string
    {
        if ($groups === []) {
            return '<p class="sitrep-empty">No population evidence reported.</p>';
        }

        if (count($groups) === 1) {
            return $this->evidenceTable(['Signal', 'Reports', 'People', 'Notes'], $groups[0]['rows']);
        }

        $html = '<div class="sitrep-population-evidence-groups">';
        foreach ($groups as $group) {
            $html .= '<section class="sitrep-population-evidence-card"><h4>'.Html::text($group['location']).'</h4>';
            $html .= $this->evidenceTable(['Signal', 'Reports', 'People', 'Notes'], $group['rows']).'</section>';
        }

        return $html.'</div>';
    }

    /**
     * @param array<string, mixed> $gap
     * @return array<int, array<int, mixed>>
     */
    private function resourceEvidenceRows(array $gap): array
    {
        $categories = array_values(array_filter($gap['resource_categories'] ?? [], 'is_array'));
        if ($categories === []) {
            return [];
        }

        return $this->resourceCategoryRows($categories);
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<int, mixed>>
     */
    private function resourceCategoryRows(array $categories): array
    {
        return array_map(function (array $row): array {
            $resources = $row['resources'] ?? [];

            return [
                $row['category'] ?? 'Uncategorized',
                $row['quantity_requested'] ?? $row['quantity'] ?? 0,
                is_array($resources) ? implode(', ', array_filter($resources, 'is_scalar')) : $resources,
            ];
        }, $categories);
    }

    /**
     * @param array<string, mixed> $gap
     * @param array<string, mixed> $needs
     * @return array<int, array{location: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}>
     */
    private function resourceEvidenceGroups(array $gap, array $needs, ?string $targetName): array
    {
        $sourceHubs = array_values(array_filter((array) ($gap['source_hubs'] ?? []), 'is_scalar'));
        if ($sourceHubs !== []) {
            $sourceGroups = $this->resourceEvidenceGroupsFromNeeds($needs, $targetName, $sourceHubs);
            if ($sourceGroups !== []) {
                return $sourceGroups;
            }
        }

        $rows = $this->resourceEvidenceRows($gap);
        if ($rows !== []) {
            return [[
                'location' => $this->resourceEvidenceLocation($gap, $targetName),
                'headers' => ['Category', 'Quantity', 'Resources'],
                'rows' => $rows,
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $needs
     * @return array<int, array{location: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}>
     */
    private function resourceEvidenceGroupsFromNeeds(array $needs, ?string $targetName, array $sourceHubs): array
    {
        $items = array_values(array_filter($needs['items'] ?? [], 'is_array'));
        if ($items === []) {
            return [];
        }

        $locations = [];
        $allowedLocations = array_fill_keys(array_map(fn (mixed $source): string => $this->shortLocation((string) $source, $targetName), $sourceHubs), true);
        foreach ($items as $item) {
            $resource = trim((string) ($item['resource'] ?? ''));
            $category = trim((string) ($item['category'] ?? 'Uncategorized'));
            foreach (array_filter($item['sources'] ?? [], 'is_array') as $source) {
                $sourceName = trim((string) ($source['source_hub_name'] ?? ''));
                if ($sourceName === '') {
                    continue;
                }

                $location = $this->shortLocation($sourceName, $targetName);
                if (! isset($allowedLocations[$location])) {
                    continue;
                }
                $quantity = (int) ($source['quantity_requested'] ?? 0);
                $locations[$location] ??= [];
                $locations[$location][$category] ??= [
                    'category' => $category,
                    'quantity_requested' => 0,
                    'resources' => [],
                ];
                $locations[$location][$category]['quantity_requested'] += $quantity;
                if ($resource !== '') {
                    $locations[$location][$category]['resources'][$resource] = $resource;
                }
            }
        }

        $groups = [];
        foreach ($locations as $location => $categories) {
            $categoryRows = array_map(function (array $row): array {
                $resources = array_values($row['resources'] ?? []);

                return [
                    $row['category'],
                    $row['quantity_requested'],
                    implode(', ', $resources),
                ];
            }, array_values($categories));

            if ($categoryRows !== []) {
                $groups[] = [
                    'location' => $location,
                    'headers' => ['Category', 'Quantity', 'Resources'],
                    'rows' => $categoryRows,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private function resourceEvidenceRowsFromSourceRows(array $rows): array
    {
        $resourceRows = [];
        foreach ($rows as $row) {
            $location = trim((string) ($row[0] ?? ''));
            $evidence = trim((string) ($row[1] ?? ''));
            if (! preg_match('/^(\d+)\s+requested\s+resource\s+units?\s+remain\s+tied\s+to\s+active\/deferred\s+incidents\.?\s*(.*)$/i', $evidence, $matches)) {
                return [];
            }

            $resourceRows[] = [$location, $matches[1], trim($matches[2])];
        }

        return $resourceRows;
    }

    /**
     * @param array<int, array{location: mixed, headers: array<int, string>, rows: array<int, array<int, mixed>>}> $groups
     */
    private function resourceEvidenceCards(array $groups): string
    {
        if ($groups === []) {
            return '<p class="sitrep-empty">No resource evidence reported.</p>';
        }

        $html = '<div class="sitrep-resource-evidence-groups">';
        foreach ($groups as $group) {
            $location = trim((string) ($group['location'] ?? 'Location'));
            $headers = $group['headers'];
            $rows = $group['rows'];
            if (count($groups) === 1) {
                return $this->evidenceTable($headers, $rows);
            }
            $html .= '<section class="sitrep-resource-evidence-card"><h4>'.Html::text($location !== '' ? $location : 'Location').'</h4>';
            $html .= $this->evidenceTable($headers, $rows).'</section>';
        }

        return $html.'</div>';
    }

    /**
     * @param array<string, mixed> $gap
     */
    private function resourceEvidenceLocation(array $gap, ?string $targetName): string
    {
        $sourceHubs = array_values(array_filter((array) ($gap['source_hubs'] ?? []), 'is_scalar'));
        if (count($sourceHubs) === 1) {
            return $this->shortLocation((string) $sourceHubs[0], $targetName);
        }

        return $sourceHubs === [] ? 'Current Location' : 'All Locations';
    }

    /**
     * @param array<string, mixed> $gap
     * @return array<int, array{location: string, rows: array<int, array<int, mixed>>}>
     */
    private function routeEvidenceGroups(array $gap, ?string $targetName): array
    {
        $items = array_values(array_filter($gap['items'] ?? [], 'is_array'));
        if ($items === []) {
            return [];
        }

        $groups = [];
        foreach ($items as $item) {
            $route = trim((string) ($item['route_location'] ?? ''));
            if ($route === '') {
                continue;
            }

            $source = trim((string) ($item['source_hub_name'] ?? $item['location'] ?? 'Location'));
            $location = $this->shortLocation($source !== '' ? $source : 'Location', $targetName);
            $groups[$location] ??= [
                'location' => $location,
                'rows' => [],
            ];
            $groups[$location]['rows'][] = [
                $route,
                $item['status'] ?? 'Reported',
                $item['obstruction_type'] ?? '',
                ! empty($item['cleared']) ? $item['cleared'] : '',
            ];
        }

        return array_values(array_filter($groups, fn (array $group): bool => $group['rows'] !== []));
    }

    /**
     * @param array<int, array{location: string, rows: array<int, array<int, mixed>>}> $groups
     */
    private function routeEvidenceCards(array $groups): string
    {
        if ($groups === []) {
            return '<p class="sitrep-empty">No route evidence reported.</p>';
        }

        $html = '<div class="sitrep-route-evidence-groups">';
        foreach ($groups as $group) {
            if (count($groups) === 1) {
                return $this->evidenceTable(['Route', 'Status', 'Obstruction', 'Cleared'], $group['rows']);
            }
            $html .= '<section class="sitrep-route-evidence-card"><h4>'.Html::text($group['location']).'</h4>';
            $html .= $this->evidenceTable(['Route', 'Status', 'Obstruction', 'Cleared'], $group['rows']).'</section>';
        }

        return $html.'</div>';
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function evidenceTable(array $headers, array $rows): string
    {
        $html = '<table class="sitrep-table sitrep-evidence-table"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>'.Html::text($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.Html::text($cell).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /**
     * @param array<string, mixed> $situation
     */
    private function periodActivity(array $situation): string
    {
        $activity = $situation['period_activity'] ?? null;
        if (! is_array($activity) || $activity === []) {
            return '';
        }

        return '<section class="sitrep-section">'
            .$this->sectionHead('Period Activity', 'Report Status History')
            .'<div class="sitrep-metrics is-compact">'
            .$this->metric('Total reports', $activity['total_reports'] ?? 0)
            .$this->metric('Open at close', $activity['open_at_close'] ?? 0)
            .$this->metric('Resolved', $activity['resolved_during_period'] ?? 0)
            .$this->metric('Discarded / excluded', $activity['discarded_excluded'] ?? 0)
            .'</div>'
            .'<p class="sitrep-note">'.Html::text($activity['note'] ?? '').'</p>'
            .'</section>';
    }

    /**
     * @param array<string, mixed> $situation
     */
    private function verificationNotes(array $situation): string
    {
        $notes = array_filter($situation['verification_notes'] ?? [], fn (mixed $note): bool => trim((string) $note) !== '');
        if ($notes === []) {
            return '';
        }

        $html = '<section class="sitrep-section">'.$this->sectionHead('Verification', 'Verification Notes').'<div class="sitrep-watch">';
        foreach ($notes as $note) {
            $html .= '<p>'.Html::text($note).'</p>';
        }

        return $html.'</div></section>';
    }

    private function footer(SitrepPayload $sitrep): string
    {
        $dataQuality = $sitrep->section('data_quality');
        $gaps = $sitrep->section('gaps');
        $redactions = $sitrep->section('privacy_redactions');
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $hotline = is_array($sourceSnapshot['hotline'] ?? null) ? $sourceSnapshot['hotline'] : [];
        $build = is_array($hotline['build'] ?? null) ? $hotline['build'] : [];
        $generation = is_array($sourceSnapshot['generation'] ?? null) ? $sourceSnapshot['generation'] : [];
        $target = is_array($sourceSnapshot['target'] ?? null) ? $sourceSnapshot['target'] : [];
        $sourceSitreps = is_array($sourceSnapshot['source_sitreps'] ?? null) ? $sourceSnapshot['source_sitreps'] : [];
        $hubSource = $this->hubNode($sourceSnapshot);
        $hub = ($hubSource['available'] ?? false) && is_array($hubSource['snapshot'] ?? null) ? $hubSource['snapshot'] : [];
        $uplinks = is_array($hub['uplinks'] ?? null) ? $hub['uplinks'] : [];
        $primaryUplink = $this->firstPrimary($uplinks);

        $privacy = [];
        foreach ($redactions as $key => $value) {
            $privacy[] = str_replace('_', ' ', (string) $key).': '.(string) $value;
        }

        $sourceLines = [];
        $hotlineVersion = $hotline['display_version'] ?? $hotline['version'] ?? null;
        if ($hotlineVersion !== null) {
            $sourceLines[] = $this->inlineParts([
                'Hotline: '.$hotlineVersion,
                ! empty($build['id']) ? 'Build '.$build['id'] : null,
            ]);
        }
        if (! empty($hub['name'])) {
            $sourceLines[] = $this->inlineParts([
                'Hub Node: '.$this->formatHubLabel($hub['name']),
                ! empty($hub['deployment']) ? $this->formatDeploymentLabel($hub['deployment']) : null,
                ! empty($hub['relay_hub_id']) ? $hub['relay_hub_id'] : null,
            ]);
        }
        if ($primaryUplink !== null) {
            $uplinkName = $primaryUplink['hub']['name'] ?? $primaryUplink['uplink_domain'] ?? null;
            if ($uplinkName) {
                $sourceLines[] = Html::text('Uplink: '.$this->formatHubLabel($uplinkName));
            }
        }
        if (($generation['type'] ?? null) === 'consolidated') {
            $sourceLines[] = $this->inlineParts([
                'Consolidated by '.$this->formatSdkLabel($generation['sdk'] ?? 'pbb-sitrep-consolidator'),
                ! empty($generation['sdk_version']) ? 'SDK '.$generation['sdk_version'] : null,
                ! empty($generation['merge_rule_version']) ? 'Merge rule '.$generation['merge_rule_version'] : null,
            ]);
        }
        if ($target !== []) {
            $sourceLines[] = $this->inlineParts([
                'Target: '.($target['name'] ?? 'Consolidated coverage'),
                ! empty($target['level']) ? $this->formatDeploymentLabel($target['level']) : null,
                ! empty($target['hub_id']) ? $target['hub_id'] : null,
            ]);
        }
        if ($sourceSitreps !== []) {
            $sourceLines[] = Html::text(sprintf('Sources: %d accepted SITREP%s', count($sourceSitreps), count($sourceSitreps) === 1 ? '' : 's'));
        }

        $sourceHtml = '';
        foreach ($sourceLines as $line) {
            $sourceHtml .= '<span>'.$line.'</span>'."\n";
        }

        return '<footer class="sitrep-footer">'
            .'<div><strong>Data Quality</strong><p>'.Html::text($dataQuality['global_note'] ?? 'Generated from current PBB data.').'</p>'.$this->countingNotes($dataQuality, $gaps).'</div>'
            .'<div><strong>Privacy Defaults</strong><p>'.Html::text(implode(', ', $privacy)).'</p></div>'
            .'<div><strong>Source Snapshot</strong><p class="sitrep-source-lines">'.$sourceHtml.'</p></div>'
            .'</footer>';
    }

    /**
     * @param array<string, mixed> $dataQuality
     * @param array<string, mixed> $gaps
     */
    private function countingNotes(array $dataQuality, array $gaps): string
    {
        $notes = array_values(array_filter($dataQuality['counting_notes'] ?? [], 'is_array'));
        foreach (array_filter($gaps['items'] ?? [], 'is_array') as $gap) {
            if ($this->isCountingScopeGap($gap)) {
                $notes[] = $gap;
            }
        }

        if ($notes === []) {
            return '';
        }

        $html = '<div class="sitrep-counting-notes"><span>Counting Notes</span><ul>';
        foreach ($notes as $note) {
            $html .= '<li>';
            $html .= '<strong>'.Html::text($note['title'] ?? 'Counting note').'</strong>';
            foreach (['body', 'evidence', 'confidence_note'] as $field) {
                $text = trim((string) ($note[$field] ?? ''));
                if ($text !== '') {
                    $html .= '<p>'.Html::text($text).'</p>';
                }
            }
            $html .= '</li>';
        }

        return $html.'</ul></div>';
    }

    /**
     * @param array<string, mixed> $gap
     */
    private function isCountingScopeGap(array $gap): bool
    {
        $type = strtolower(trim((string) ($gap['type'] ?? '')));
        $category = strtolower(trim((string) ($gap['category'] ?? '')));

        return $type === 'counting_scope' || $category === 'counting rule';
    }

    private function sectionHead(string $eyebrow, string $title): string
    {
        return '<div class="sitrep-section-head"><p class="sitrep-eyebrow">'.Html::text($eyebrow).'</p><h2>'.Html::text($title).'</h2></div>';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function locationCount(array $row): int
    {
        if (isset($row['location_count'])) {
            return max(0, (int) $row['location_count']);
        }

        $sourceHubs = array_unique(array_filter((array) ($row['source_hubs'] ?? []), 'is_scalar'));
        if ($sourceHubs !== []) {
            return count($sourceHubs);
        }

        return 1;
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     */
    private function cardRow(string $title, array $cards, bool $positive = false, ?string $targetName = null): string
    {
        return '<div class="sitrep-card-row'.($positive ? ' is-positive' : '').'"><h3>'.Html::text($title).'</h3>'.$this->pictureGrid($cards, $targetName).'</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     */
    private function pictureGrid(array $cards, ?string $targetName = null): string
    {
        $html = '<div class="sitrep-picture-grid">';
        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }
            $html .= '<article><span>'.Html::text($card['label'] ?? 'Summary').'</span>'
                .'<strong>'.Html::text($card['value'] ?? 'Not reported').'</strong>'
                .$this->cardDetail($card, $targetName)
                .'</article>';
        }

        return $html.'</div>';
    }

    /**
     * @param array<string, mixed> $card
     */
    private function cardDetail(array $card, ?string $targetName): string
    {
        $sourceValues = array_values(array_filter($card['source_values'] ?? [], 'is_array'));
        if ($sourceValues === []) {
            return '<p>'.Html::text($card['note'] ?? 'Generated from available records.').'</p>';
        }

        $html = '<ul class="sitrep-card-sources">';
        foreach (array_slice($sourceValues, 0, 5) as $row) {
            $source = $this->shortLocation((string) ($row['source_hub_name'] ?? 'Source'), $targetName);
            $label = trim((string) ($row['label'] ?? $row['value'] ?? 'Reported'));
            $html .= '<li><strong>'.Html::text($source).'</strong><span>'.Html::text($label).'</span></li>';
        }
        $remaining = count($sourceValues) - 5;
        if ($remaining > 0) {
            $html .= '<li><strong>More sources</strong><span>'.Html::text($remaining.' additional source'.($remaining === 1 ? '' : 's')).'</span></li>';
        }

        return $html.'</ul>';
    }

    /**
     * @param array<string, mixed> $sourceSnapshot
     */
    private function targetName(array $sourceSnapshot): ?string
    {
        $targetName = trim((string) ($sourceSnapshot['target']['name'] ?? ''));
        if ($targetName !== '') {
            return $targetName;
        }

        $hubNode = is_array($sourceSnapshot['hub_node'] ?? null) ? $sourceSnapshot['hub_node'] : [];
        $snapshot = is_array($hubNode['snapshot'] ?? null) ? $hubNode['snapshot'] : [];
        $name = trim((string) ($snapshot['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    private function shortLocation(string $location, ?string $targetName): string
    {
        $short = trim($location);
        $target = trim((string) $targetName);

        if ($target !== '') {
            $short = preg_replace('/,\s*'.preg_quote($target, '/').'$/i', '', $short) ?? $short;
        }

        $short = preg_replace('/^Barangay\s+/i', '', $short) ?? $short;

        return trim($short) !== '' ? trim($short) : $location;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function table(string $title, array $headers, array $rows, string $empty, string $class = ''): string
    {
        $html = '<div class="sitrep-table-card">'.(trim($title) !== '' ? '<h3>'.Html::text($title).'</h3>' : '');
        if ($rows === []) {
            return $html.'<p class="sitrep-empty">'.Html::text($empty).'</p></div>';
        }

        if ($class === 'is-team-deployment') {
            return $html.$this->teamDeploymentGroups($headers, $rows).'</div>';
        }

        if ($class === 'is-assignment-timing') {
            return $html.$this->assignmentTimingGroups($headers, $rows).'</div>';
        }

        if ($this->layout === 'compact' && $class === 'is-resource-needs') {
            return $html.$this->resourceNeedGroups($headers, $rows).'</div>';
        }

        if ($this->layout === 'compact' && $class === 'is-category-demand') {
            return $html.$this->propertyList($headers, $rows).'</div>';
        }

        if ($this->layout === 'compact' && count($headers) > 4) {
            return $html.$this->propertyList($headers, $rows).'</div>';
        }

        $html .= '<table class="sitrep-table '.Html::escape($class).'"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>'.Html::text($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.Html::text($cell).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table></div>';
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function teamDeploymentGroups(array $headers, array $rows): string
    {
        $statusHeaders = array_slice($headers, 2);
        $groups = [];

        foreach ($rows as $row) {
            $category = trim((string) ($row[0] ?? 'Uncategorized'));
            $team = trim((string) ($row[1] ?? 'Team'));
            $groups[$category !== '' ? $category : 'Uncategorized'][] = [
                'team' => $team !== '' ? $team : 'Team',
                'statuses' => array_map(
                    fn (string $label, int $offset): array => [
                        'label' => $label,
                        'value' => $row[$offset + 2] ?? null,
                    ],
                    $statusHeaders,
                    array_keys($statusHeaders),
                ),
            ];
        }

        $html = '<div class="sitrep-team-groups">';
        foreach ($groups as $category => $teams) {
            $html .= '<section class="sitrep-team-group"><h4>'.Html::text($category).'</h4>';
            $html .= '<table class="sitrep-team-matrix"><thead><tr><th>Team</th>';
            foreach ($statusHeaders as $header) {
                $html .= '<th>'.Html::text($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($teams as $team) {
                $html .= '<tr><td>'.Html::text($team['team']).'</td>';
                foreach ($team['statuses'] as $status) {
                    $html .= '<td>'.Html::text($this->statusValue($status['value'])).'</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody></table><div class="sitrep-team-cards">';
            foreach ($teams as $team) {
                $html .= '<article><strong>'.Html::text($team['team']).'</strong><ul>';
                foreach ($team['statuses'] as $status) {
                    if ($this->isEmptyPropertyValue($status['value'])) {
                        continue;
                    }
                    $html .= '<li><span>'.Html::text($status['label']).'</span><b>'.Html::text($this->statusValue($status['value'])).'</b></li>';
                }
                $html .= '</ul></article>';
            }
            $html .= '</div></section>';
        }

        return $html.'</div>';
    }

    private function statusValue(mixed $value): string
    {
        if ($this->isEmptyPropertyValue($value)) {
            return '-';
        }

        return trim((string) $value);
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function resourceNeedGroups(array $headers, array $rows): string
    {
        $categoryIndex = array_search('Category', $headers, true);
        $resourceIndex = array_search('Resource', $headers, true);
        if ($categoryIndex === false || $resourceIndex === false) {
            return $this->propertyList($headers, $rows);
        }

        $metricIndexes = [];
        foreach ($headers as $index => $header) {
            if ($index === $categoryIndex || $index === $resourceIndex) {
                continue;
            }
            $metricIndexes[$index] = $header;
        }

        $groups = [];
        foreach ($rows as $row) {
            $category = trim((string) ($row[$categoryIndex] ?? 'Uncategorized'));
            $resource = trim((string) ($row[$resourceIndex] ?? 'Resource'));
            $groups[$category !== '' ? $category : 'Uncategorized'][] = [
                'resource' => $resource !== '' ? $resource : 'Resource',
                'metrics' => array_map(
                    fn (string $label, int $index): array => [
                        'label' => $label,
                        'value' => $row[$index] ?? null,
                    ],
                    array_values($metricIndexes),
                    array_keys($metricIndexes),
                ),
            ];
        }

        $html = '<div class="sitrep-resource-groups">';
        foreach ($groups as $category => $resources) {
            $html .= '<section class="sitrep-resource-group"><h4>'.Html::text($category).'</h4>';
            $html .= '<table class="sitrep-resource-matrix"><thead><tr><th>Resource</th>';
            foreach ($metricIndexes as $header) {
                $html .= '<th>'.Html::text($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($resources as $resource) {
                $html .= '<tr><td>'.Html::text($resource['resource']).'</td>';
                foreach ($resource['metrics'] as $metric) {
                    $html .= '<td>'.Html::text($this->statusValue($metric['value'])).'</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody></table><div class="sitrep-resource-cards">';
            foreach ($resources as $resource) {
                $html .= '<article><strong>'.Html::text($resource['resource']).'</strong><ul>';
                foreach ($resource['metrics'] as $metric) {
                    if ($this->isEmptyPropertyValue($metric['value'])) {
                        continue;
                    }
                    $html .= '<li><span>'.Html::text($metric['label']).'</span><b>'.Html::text($this->statusValue($metric['value'])).'</b></li>';
                }
                $html .= '</ul></article>';
            }
            $html .= '</div></section>';
        }

        return $html.'</div>';
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function assignmentTimingGroups(array $headers, array $rows): string
    {
        $teamIndex = array_search('Team', $headers, true);
        $incidentIndex = array_search('Incident', $headers, true);
        if ($teamIndex === false || $incidentIndex === false) {
            return $this->propertyList($headers, $rows);
        }

        $statusHeaders = [];
        foreach ($headers as $index => $header) {
            if ($index === $teamIndex || $index === $incidentIndex) {
                continue;
            }
            $statusHeaders[$index] = $header;
        }

        $groups = [];
        foreach ($rows as $row) {
            $team = trim((string) ($row[$teamIndex] ?? 'Team'));
            $incident = trim((string) ($row[$incidentIndex] ?? 'Incident'));
            $groups[$team !== '' ? $team : 'Team'][] = [
                'incident' => $incident !== '' ? $incident : 'Incident',
                'statuses' => array_map(
                    fn (string $label, int $index): array => [
                        'label' => $label,
                        'value' => $row[$index] ?? null,
                    ],
                    array_values($statusHeaders),
                    array_keys($statusHeaders),
                ),
            ];
        }

        $html = '<div class="sitrep-assignment-groups">';
        foreach ($groups as $team => $incidents) {
            $html .= '<section class="sitrep-assignment-group"><h4>'.Html::text($team).'</h4>';
            $html .= '<table class="sitrep-assignment-matrix"><thead><tr><th>Incident</th>';
            foreach ($statusHeaders as $header) {
                $html .= '<th>'.Html::text($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($incidents as $incident) {
                $html .= '<tr><td>'.Html::text($incident['incident']).'</td>';
                foreach ($incident['statuses'] as $status) {
                    $html .= '<td>'.Html::text($this->statusValue($status['value'])).'</td>';
                }
                $html .= '</tr>';
            }

            $html .= '</tbody></table><div class="sitrep-assignment-cards">';
            foreach ($incidents as $incident) {
                $html .= '<article><strong>'.Html::text($incident['incident']).'</strong><ul>';
                foreach ($incident['statuses'] as $status) {
                    if ($this->isEmptyPropertyValue($status['value'])) {
                        continue;
                    }
                    $html .= '<li><span>'.Html::text($status['label']).'</span><b>'.Html::text($this->statusValue($status['value'])).'</b></li>';
                }
                $html .= '</ul></article>';
            }
            $html .= '</div></section>';
        }

        return $html.'</div>';
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function propertyList(array $headers, array $rows): string
    {
        $html = '<div class="sitrep-property-list">';
        $titleHeader = $headers[0] ?? 'Group';

        foreach ($rows as $row) {
            $title = trim((string) ($row[0] ?? ''));
            $html .= '<article class="sitrep-property-group">';
            $html .= '<h4><span>'.Html::text($titleHeader).'</span>'.Html::text($title !== '' ? $title : 'Item').'</h4>';
            $html .= '<dl>';

            foreach ($headers as $index => $header) {
                if ($index === 0) {
                    continue;
                }

                $value = $row[$index] ?? null;
                if ($this->isEmptyPropertyValue($value)) {
                    continue;
                }

                $html .= '<div><dt>'.Html::text($header).'</dt><dd>'.$this->propertyValue($header, $value).'</dd></div>';
            }

            $html .= '</dl></article>';
        }

        return $html.'</div>';
    }

    private function isEmptyPropertyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return trim((string) $value) === '';
    }

    private function propertyValue(string $header, mixed $value): string
    {
        $text = trim((string) $value);

        if (strcasecmp($header, 'Main Signals') === 0) {
            $parts = array_values(array_filter(array_map('trim', explode(';', $text)), fn (string $part): bool => $part !== ''));
            if (count($parts) > 1) {
                $html = '<ul class="sitrep-property-bullets">';
                foreach ($parts as $part) {
                    $html .= '<li>'.Html::text($part).'</li>';
                }

                return $html.'</ul>';
            }
        }

        return Html::text($text);
    }

    /**
     * @param array<int, mixed> $items
     */
    private function factList(array $items, string $empty): string
    {
        $items = array_filter($items, 'is_array');
        if ($items === []) {
            return '<p class="sitrep-empty">'.Html::text($empty).'</p>';
        }

        $html = '<div class="sitrep-fact-list">';
        foreach ($items as $item) {
            $html .= '<article class="sitrep-fact"><span>#'.Html::text(str_pad((string) ($item['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT)).'</span>'
                .'<strong>'.Html::text($item['label'] ?? 'Reported fact').'</strong>'
                .'<p>'.Html::text((string) ($item['value'] ?? '').(isset($item['unit']) && $item['unit'] ? ' '.$item['unit'] : '')).'</p></article>';
        }

        return $html.'</div>';
    }

    private function metric(string $label, mixed $value, string $class = ''): string
    {
        $className = trim('sitrep-metric '.$class);

        return '<div class="'.Html::text($className).'"><span>'.Html::text($label).'</span><strong>'.Html::number($value).'</strong></div>';
    }

    /**
     * @return array<string, string|null>
     */
    private function identity(SitrepPayload $sitrep): array
    {
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $hubSource = $this->hubNode($sourceSnapshot);
        $hub = ($hubSource['available'] ?? false) && is_array($hubSource['snapshot'] ?? null) ? $hubSource['snapshot'] : [];
        $generation = is_array($sourceSnapshot['generation'] ?? null) ? $sourceSnapshot['generation'] : [];
        $target = is_array($sourceSnapshot['target'] ?? null) ? $sourceSnapshot['target'] : [];
        $deployment = trim((string) ($hub['deployment'] ?? ''));
        $hubName = trim((string) ($hub['name'] ?? ''));
        $period = $this->periodLabel($sitrep->get('period_started_at'), $sitrep->get('period_ended_at'));
        $title = trim((string) preg_replace('/\s+-\s+\d{4}-\d{2}-\d{2}\s*$/', '', (string) $sitrep->get('title')));

        if (($generation['type'] ?? null) === 'consolidated' && $target !== []) {
            $targetLevel = trim((string) ($target['level'] ?? ''));
            $targetName = trim((string) ($target['name'] ?? ''));
            if ($targetLevel !== '') {
                $title = ucfirst(str_replace(['_', '-'], ' ', strtolower($targetLevel))).' SITREP';
            }

            return [
                'title' => $title !== '' ? $title : 'Consolidated SITREP',
                'hub' => $targetName !== '' ? $this->formatHubLabel($targetName) : null,
                'period' => $period,
            ];
        }

        if ($deployment !== '' && $hubName !== '') {
            $title = ucfirst(str_replace(['_', '-'], ' ', strtolower($deployment))).' SITREP';
        }

        return [
            'title' => $title !== '' ? $title : 'Daily SITREP',
            'hub' => $hubName !== '' ? $this->formatHubLabel($hubName) : null,
            'period' => $period,
        ];
    }

    /**
     * @param array<string, mixed> $sourceSnapshot
     * @return array<string, mixed>
     */
    private function hubNode(array $sourceSnapshot): array
    {
        if (isset($sourceSnapshot['hub_node']) && is_array($sourceSnapshot['hub_node'])) {
            return $sourceSnapshot['hub_node'];
        }

        if (isset($sourceSnapshot['hub_nodes']) && is_array($sourceSnapshot['hub_nodes'])) {
            foreach ($sourceSnapshot['hub_nodes'] as $hubNode) {
                if (is_array($hubNode)) {
                    return $hubNode;
                }
            }
        }

        return [];
    }

    private function periodLabel(mixed $start, mixed $end): string
    {
        $startText = $this->formatDate($start, 'M d, Y');
        $endText = $this->formatDate($end, 'M d, Y');

        if ($startText === '' && $endText === '') {
            return 'Reporting period';
        }
        if ($startText === $endText || $endText === '') {
            return $startText;
        }

        return $startText.' - '.$endText;
    }

    private function formatDate(mixed $value, string $format = 'M d, Y g:i A'): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($text))->format($format);
        } catch (\Throwable) {
            return $text;
        }
    }

    private function formatHubLabel(mixed $value): string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $value)), fn (string $part): bool => $part !== '');

        return implode(', ', array_map(fn (string $part): string => ucwords(strtolower($part)), $parts));
    }

    private function formatDeploymentLabel(mixed $value): string
    {
        $text = str_replace(['_', '-'], ' ', trim((string) $value));

        return $text !== '' ? ucwords(strtolower($text)) : '';
    }

    private function formatSdkLabel(mixed $value): string
    {
        $text = str_replace(['_', '-'], ' ', trim((string) $value));

        return $text !== '' ? $text : 'SITREP consolidator';
    }

    /**
     * @param array<int, mixed> $parts
     */
    private function inlineParts(array $parts): string
    {
        $filtered = array_values(array_filter(array_map(
            fn (mixed $part): string => trim((string) $part),
            $parts,
        ), fn (string $part): bool => $part !== ''));

        $html = '';
        foreach ($filtered as $index => $part) {
            if ($index > 0) {
                $html .= ' <span class="sitrep-separator">&middot;</span> ';
            }
            $html .= '<span>'.Html::text($part).'</span>';
        }

        return $html;
    }

    /**
     * @param array<int, mixed> $uplinks
     * @return array<string, mixed>|null
     */
    private function firstPrimary(array $uplinks): ?array
    {
        foreach ($uplinks as $uplink) {
            if (is_array($uplink) && ($uplink['is_primary'] ?? false)) {
                return $uplink;
            }
        }

        foreach ($uplinks as $uplink) {
            if (is_array($uplink)) {
                return $uplink;
            }
        }

        return null;
    }
}
