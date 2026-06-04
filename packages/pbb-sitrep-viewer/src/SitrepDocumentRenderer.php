<?php

namespace Pbb\Sitreps\Viewer;

final class SitrepDocumentRenderer
{
    public function render(SitrepPayload $sitrep, SitrepViewOptions $options): string
    {
        $summary = $sitrep->section('summary');
        $situation = $sitrep->section('situation');
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $identity = $this->identity($sitrep);
        $classes = ['pbb-sitrep-viewer', 'sitrep-page'];
        if ($options->preview) {
            $classes[] = 'is-preview';
        }
        if ($options->pdf) {
            $classes[] = 'is-pdf';
        }
        if ($sitrep->get('status') === 'draft') {
            $classes[] = 'is-draft';
        }

        return '<main class="'.Html::escape(implode(' ', $classes)).'">'
            .'<article class="sitrep-document">'
            .$this->previewBanner($sitrep, $options)
            .$this->header($sitrep, $summary, $sourceSnapshot, $identity)
            .$this->summary($summary, $situation)
            .$this->situation($situation)
            .$this->damage($sitrep->section('damage'))
            .$this->population($sitrep->section('population'))
            .$this->actions($sitrep->section('actions'))
            .$this->needs($sitrep->section('needs'))
            .$this->gaps($sitrep->section('gaps'))
            .$this->periodActivity($situation)
            .$this->verificationNotes($situation)
            .$this->footer($sitrep)
            .'</article>'
            .'</main>';
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
     */
    private function summary(array $summary, array $situation): string
    {
        $html = '<section class="sitrep-section sitrep-summary">'
            .$this->sectionHead('Summary', 'Executive Situation Assessment')
            .'<p class="sitrep-narrative">'.Html::text($situation['executive_assessment'] ?? $situation['narrative'] ?? 'No executive assessment is available.').'</p>';

        if (! empty($summary['gap_cards']) && is_array($summary['gap_cards'])) {
            $html .= $this->cardRow('Gaps', $summary['gap_cards']);
        }

        if (! empty($summary['accomplishment_cards']) && is_array($summary['accomplishment_cards'])) {
            $html .= $this->cardRow('Accomplishments', $summary['accomplishment_cards'], true);
        } elseif (! empty($summary['executive_cards']) && is_array($summary['executive_cards'])) {
            $html .= $this->pictureGrid($summary['executive_cards']);
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
            ]);
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

        $locationRows = array_map(fn (array $row): array => [$row['area'] ?? 'Unknown', $row['count'] ?? $row['report_count'] ?? 0], array_filter($situation['locations'] ?? [], 'is_array'));
        $typeRows = array_map(fn (array $row): array => [$row['type'] ?? 'Unclassified', $row['count'] ?? 0], array_filter($situation['incident_types'] ?? [], 'is_array'));

        return $html.'<div class="sitrep-two-column">'
            .$this->table('Current Locations', ['Area', 'Incidents'], $locationRows, 'No location distribution available.')
            .$this->table('Current Incident Types', ['Type', 'Mentions'], $typeRows, 'No incident type distribution available.')
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
    private function population(array $population): string
    {
        $html = '<section class="sitrep-section">'.$this->sectionHead('Population', 'Affected People')
            .'<div class="sitrep-metrics is-compact">'
            .$this->metric('Citizens assisted', $population['citizens_assisted'] ?? $population['callers_assisted'] ?? 0)
            .$this->metric('Current records', $population['record_count'] ?? count($population['items'] ?? []))
            .'</div>';

        if (! empty($population['numeric_total_note'])) {
            $html .= '<p class="sitrep-note">'.Html::text($population['numeric_total_note']).'</p>';
        }

        if (! empty($population['population_groups']) && is_array($population['population_groups'])) {
            $rows = array_map(fn (array $row): array => [
                $row['population_signal'] ?? 'Population signal',
                $row['reports'] ?? 0,
                $row['people_families'] ?? '',
                $row['notes'] ?? '',
            ], array_filter($population['population_groups'], 'is_array'));
            $html .= $this->table('Population Summary', ['Population Signal', 'Reports', 'People / Families', 'Notes'], $rows, (string) ($population['empty_state'] ?? 'No population entries available.'));

            $breakdownRows = [];
            foreach ($population['population_groups'] as $group) {
                if (! is_array($group)) {
                    continue;
                }
                foreach (($group['breakdowns'] ?? []) as $row) {
                    if (is_array($row)) {
                        $breakdownRows[] = [$group['population_signal'] ?? 'Population signal', $row['breakdown'] ?? 'Breakdown', $row['count'] ?? 0];
                    }
                }
            }
            if ($breakdownRows !== []) {
                $html .= $this->table('Declared Member Breakdown', ['Population Signal', 'Breakdown', 'Count'], $breakdownRows, 'No member breakdowns declared.');
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
                ($counts['completed'] ?? 0) ?: '',
                ($counts['cancelled'] ?? 0) ?: '',
            ];
        }, array_filter($actions['deployment_groups'] ?? [], 'is_array'));

        $timingRows = array_map(fn (array $row): array => [
            '#'.str_pad((string) ($row['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT),
            $row['team'] ?? 'Team',
            $row['current_status'] ?? '',
            $row['assigned_to_accepted'] ?? '',
            $row['accepted_to_en_route'] ?? '',
            $row['en_route_to_on_scene'] ?? '',
            $row['on_scene_to_completed'] ?? '',
            $row['assigned_to_cancelled'] ?? '',
            $row['elapsed_time'] ?? '',
        ], array_filter($actions['timing_rows'] ?? [], 'is_array'));

        return '<section class="sitrep-section">'
            .$this->sectionHead('Actions', 'Response Posture')
            .$this->table('Team Deployment', ['Category', 'Team', 'Requested', 'Assigned', 'Accepted', 'En Route', 'On Scene', 'Completed', 'Cancelled'], $deploymentRows, 'No team assignments recorded.', 'is-team-deployment')
            .$this->table('Assignment Timing', ['Incident', 'Team', 'Status', 'Accepted', 'En Route', 'On Scene', 'Completed', 'Cancelled', 'Time in Status'], $timingRows, 'No assignment timing milestones recorded.', 'is-assignment-timing')
            .'<p class="sitrep-note">Timing rows are scenario-specific and derived from team assignment milestone timestamps. Time in Status shows how long an open assignment has been in its current status, falling back to assignment time when older records do not have the milestone timestamp.</p>'
            .'</section>';
    }

    /**
     * @param array<string, mixed> $needs
     */
    private function needs(array $needs): string
    {
        $html = '<section class="sitrep-section">'.$this->sectionHead('Needs', 'Current Resource Posture');

        if (! empty($needs['category_groups']) && is_array($needs['category_groups'])) {
            $rows = array_map(fn (array $row): array => [
                $row['category'] ?? 'Uncategorized',
                $row['quantity_requested'] ?? 0,
                implode(', ', $row['resources'] ?? []),
            ], array_filter($needs['category_groups'], 'is_array'));
            $html .= $this->table('Category Demand', ['Category', 'Quantity', 'Resources'], $rows, 'No category demand available.');
        }

        $rows = array_map(fn (array $row): array => [
            $row['resource'] ?? 'Resource',
            $row['category'] ?? 'Uncategorized',
            $row['quantity_requested'] ?? 0,
            $row['incident_count'] ?? 0,
        ], array_filter($needs['items'] ?? [], 'is_array'));

        return $html
            .$this->table('Resource Needs', ['Resource', 'Category', 'Quantity', 'Incidents'], $rows, (string) ($needs['empty_state'] ?? 'No structured resource needs recorded.'))
            .'<p class="sitrep-note">'.Html::text($needs['confidence_note'] ?? '').'</p></section>';
    }

    /**
     * @param array<string, mixed> $gaps
     */
    private function gaps(array $gaps): string
    {
        $html = '<section class="sitrep-section">'.$this->sectionHead('Gaps', (string) ($gaps['title'] ?? 'Response Constraints and Confidence Gaps'));
        if (! empty($gaps['intro'])) {
            $html .= '<p class="sitrep-narrative">'.Html::text($gaps['intro']).'</p>';
        }

        $items = array_filter($gaps['items'] ?? [], 'is_array');
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
            if (! empty($gap['evidence'])) {
                $html .= '<dl class="sitrep-gap-details">';
                $html .= '<div><dt>Evidence</dt><dd>'.Html::text($gap['evidence']).'</dd></div>';
                if (! empty($gap['confidence_note'])) {
                    $html .= '<div><dt>Confidence</dt><dd>'.Html::text($gap['confidence_note']).'</dd></div>';
                }
                $html .= '</dl>';
            }
            $details = array_filter($gap['items'] ?? [], 'is_array');
            if ($details !== []) {
                $html .= '<ul class="sitrep-gap-evidence">';
                foreach ($details as $detail) {
                    $html .= '<li><strong>'.Html::text($detail['status'] ?? 'Reported').'</strong> '
                        .'<span class="sitrep-gap-route">'.Html::text($detail['route_location'] ?? 'Location not specified').'</span>';
                    if (! empty($detail['obstruction_type'])) {
                        $html .= ' <span class="sitrep-gap-obstruction">&mdash; '.Html::text($detail['obstruction_type']).'</span>';
                    }
                    if (! empty($detail['cleared'])) {
                        $html .= ' <span class="sitrep-gap-cleared">Cleared: '.Html::text($detail['cleared']).'</span>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</article>';
        }

        return $html.'</section>';
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
        $redactions = $sitrep->section('privacy_redactions');
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $hotline = is_array($sourceSnapshot['hotline'] ?? null) ? $sourceSnapshot['hotline'] : [];
        $build = is_array($hotline['build'] ?? null) ? $hotline['build'] : [];
        $generation = is_array($sourceSnapshot['generation'] ?? null) ? $sourceSnapshot['generation'] : [];
        $target = is_array($sourceSnapshot['target'] ?? null) ? $sourceSnapshot['target'] : [];
        $sourceSitreps = is_array($sourceSnapshot['source_sitreps'] ?? null) ? $sourceSnapshot['source_sitreps'] : [];
        $hubSource = is_array($sourceSnapshot['hub_node'] ?? null) ? $sourceSnapshot['hub_node'] : [];
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
            .'<div><strong>Data Quality</strong><p>'.Html::text($dataQuality['global_note'] ?? 'Generated from current PBB data.').'</p></div>'
            .'<div><strong>Privacy Defaults</strong><p>'.Html::text(implode(', ', $privacy)).'</p></div>'
            .'<div><strong>Source Snapshot</strong><p class="sitrep-source-lines">'.$sourceHtml.'</p></div>'
            .'</footer>';
    }

    private function sectionHead(string $eyebrow, string $title): string
    {
        return '<div class="sitrep-section-head"><p class="sitrep-eyebrow">'.Html::text($eyebrow).'</p><h2>'.Html::text($title).'</h2></div>';
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     */
    private function cardRow(string $title, array $cards, bool $positive = false): string
    {
        return '<div class="sitrep-card-row'.($positive ? ' is-positive' : '').'"><h3>'.Html::text($title).'</h3>'.$this->pictureGrid($cards).'</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     */
    private function pictureGrid(array $cards): string
    {
        $html = '<div class="sitrep-picture-grid">';
        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }
            $html .= '<article><span>'.Html::text($card['label'] ?? 'Summary').'</span>'
                .'<strong>'.Html::text($card['value'] ?? 'Not reported').'</strong>'
                .'<p>'.Html::text($card['note'] ?? 'Generated from available records.').'</p></article>';
        }

        return $html.'</div>';
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    private function table(string $title, array $headers, array $rows, string $empty, string $class = ''): string
    {
        $html = '<div class="sitrep-table-card"><h3>'.Html::text($title).'</h3>';
        if ($rows === []) {
            return $html.'<p class="sitrep-empty">'.Html::text($empty).'</p></div>';
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

    private function metric(string $label, mixed $value): string
    {
        return '<div class="sitrep-metric"><span>'.Html::text($label).'</span><strong>'.Html::number($value).'</strong></div>';
    }

    /**
     * @return array<string, string|null>
     */
    private function identity(SitrepPayload $sitrep): array
    {
        $sourceSnapshot = $sitrep->section('source_snapshot');
        $hubSource = is_array($sourceSnapshot['hub_node'] ?? null) ? $sourceSnapshot['hub_node'] : [];
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
