@php
    $isPreview = $isPreview ?? false;
    $summary = $sitrep->summary_json ?? [];
    $situation = $sitrep->situation_json ?? [];
    $damage = $sitrep->damage_json ?? [];
    $population = $sitrep->population_json ?? [];
    $actions = $sitrep->actions_json ?? [];
    $needs = $sitrep->needs_json ?? [];
    $gaps = $sitrep->gaps_json ?? [];
    $dataQuality = $sitrep->data_quality_json ?? [];
    $redactions = $sitrep->privacy_redactions_json ?? [];
    $sourceSnapshot = $sitrep->source_snapshot_json ?? [];
    $hotlineSnapshot = $sourceSnapshot['hotline'] ?? [];
    $hotlineBuild = $hotlineSnapshot['build'] ?? [];
    $hotlineVersionLabel = $hotlineSnapshot['display_version'] ?? $hotlineSnapshot['version'] ?? config('app.version');
    $hotlineBuildLabel = $hotlineBuild['id'] ?? null;
    $hubNodeSource = $sourceSnapshot['hub_node'] ?? [];
    $hubNode = ($hubNodeSource['available'] ?? false) ? ($hubNodeSource['snapshot'] ?? []) : [];
    $formatHubLabel = function ($value) {
        $parts = collect(explode(',', (string) $value))
            ->map(fn ($part) => trim($part))
            ->filter()
            ->map(fn ($part) => mb_convert_case(strtolower($part), MB_CASE_TITLE, 'UTF-8'))
            ->all();

        return implode(', ', $parts);
    };
    $deploymentLabel = trim((string) ($hubNode['deployment'] ?? '')) !== ''
        ? mb_convert_case(str_replace(['_', '-'], ' ', (string) $hubNode['deployment']), MB_CASE_TITLE, 'UTF-8')
        : null;
    $hubNameLabel = trim((string) ($hubNode['name'] ?? '')) !== ''
        ? $formatHubLabel($hubNode['name'])
        : null;
    $primaryUplink = collect($hubNode['uplinks'] ?? [])->firstWhere('is_primary', true) ?? collect($hubNode['uplinks'] ?? [])->first();
    $primaryUplinkLabel = $primaryUplink
        ? $formatHubLabel(data_get($primaryUplink, 'hub.name', data_get($primaryUplink, 'uplink_domain', '')))
        : null;

    $metricLabels = [
        'total_incidents' => 'Incidents',
        'total_call_sessions' => 'Call sessions',
        'multi_call_incidents' => 'Multi-call incidents',
        'incident_type_mentions' => 'Type mentions',
        'team_assignments' => 'Assignments',
        'resource_need_units' => 'Resource units',
        'new_this_period' => 'New',
        'carried_over' => 'Carried over',
        'closed_this_period' => 'Resolved',
        'discarded_excluded' => 'Discarded / excluded',
        'active_at_close' => 'Open at close',
    ];

    $summaryMetrics = $summary['supporting_metrics'] ?? [];
    $metricGroups = [
        'Incident Flow' => ['carried_over', 'new_this_period', 'active_at_close', 'closed_this_period', 'discarded_excluded'],
        'Current Load' => ['team_assignments', 'resource_need_units'],
        'Signal Quality' => ['total_call_sessions', 'multi_call_incidents', 'incident_type_mentions'],
    ];
    $groupedMetricKeys = collect($metricGroups)->flatten()->all();
    $otherMetrics = array_diff_key($summaryMetrics, array_flip($groupedMetricKeys));
    $statusLabel = fn ($status) => collect(explode(' ', str_replace(['_', '-'], ' ', trim((string) $status) ?: 'unknown')))
        ->filter()
        ->map(fn ($part) => ucfirst(strtolower($part)))
        ->implode(' ');
    $visualTitle = trim((string) preg_replace('/\s+-\s+\d{4}-\d{2}-\d{2}\s*$/', '', $sitrep->title));
    $visualTitle = $visualTitle !== '' ? $visualTitle : 'Daily SITREP';
    $reportLabel = $visualTitle;
    if ($deploymentLabel && $hubNameLabel) {
        $visualTitle = $deploymentLabel.' SITREP';
    }
    $periodLabel = $sitrep->period_started_at && $sitrep->period_ended_at
        ? ($sitrep->period_started_at->isSameDay($sitrep->period_ended_at)
            ? $sitrep->period_started_at->format('M d, Y')
            : $sitrep->period_started_at->format('M d, Y').' - '.$sitrep->period_ended_at->format('M d, Y'))
        : 'Reporting period';
    $identityLine = collect([$hubNameLabel, $periodLabel])->filter()->implode(' · ');
@endphp

<article class="sitrep-document">
    @if ($isPreview && ! $sitrep->isPubliclyVisible())
        <div class="sitrep-preview-banner">Preview only. This SITREP is not public unless status is published and visibility is public.</div>
    @endif

    <header class="sitrep-header">
        <div>
            <p class="sitrep-eyebrow">PBB Hotline Periodic SITREP</p>
            <h1>{{ $visualTitle }}</h1>
            <p class="sitrep-periodline">{{ $identityLine }}</p>
            <p class="sitrep-headline">{{ $summary['headline'] ?? 'Situation report generated from Hotline incident records.' }}</p>
        </div>
        <p class="sitrep-metaline">
            #{{ str_pad((string) $sitrep->sequence_number, 4, '0', STR_PAD_LEFT) }}
            · {{ ucfirst($sitrep->status) }} / {{ ucfirst($sitrep->visibility) }}
            · {{ $sitrep->alert_level ?? 'Normal' }}
            · {{ $sitrep->generated_at?->format('M d, Y g:i A') }}
        </p>
    </header>

    <section class="sitrep-section sitrep-summary">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Summary</p>
            <h2>Executive Situation Assessment</h2>
        </div>
        <p class="sitrep-narrative">{{ $situation['executive_assessment'] ?? $situation['narrative'] ?? 'No executive assessment is available.' }}</p>
        @if (! empty($summary['gap_cards'] ?? []))
            <div class="sitrep-card-row">
                <h3>Gaps</h3>
                <div class="sitrep-picture-grid">
                    @foreach (($summary['gap_cards'] ?? []) as $card)
                        <article>
                            <span>{{ $card['label'] ?? 'Summary' }}</span>
                            <strong>{{ $card['value'] ?? 'Not reported' }}</strong>
                            <p>{{ $card['note'] ?? 'Generated from available incident records.' }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        @endif

        @if (! empty($summary['accomplishment_cards'] ?? []))
            <div class="sitrep-card-row is-positive">
                <h3>Accomplishments</h3>
                <div class="sitrep-picture-grid">
                    @foreach (($summary['accomplishment_cards'] ?? []) as $card)
                        <article>
                            <span>{{ $card['label'] ?? 'Summary' }}</span>
                            <strong>{{ $card['value'] ?? 'Not reported' }}</strong>
                            <p>{{ $card['note'] ?? 'Generated from available incident records.' }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        @elseif (! empty($summary['executive_cards'] ?? []))
            <div class="sitrep-picture-grid">
                @foreach (($summary['executive_cards'] ?? []) as $card)
                    <article>
                        <span>{{ $card['label'] ?? 'Summary' }}</span>
                        <strong>{{ $card['value'] ?? 'Not reported' }}</strong>
                        <p>{{ $card['note'] ?? 'Generated from available incident records.' }}</p>
                    </article>
                @endforeach
            </div>
        @else
            <div class="sitrep-picture-grid">
                <article>
                    <span>Operational Posture</span>
                    <strong>{{ $summary['posture_label'] ?? ucfirst($summary['posture'] ?? 'Monitoring') }}</strong>
                    <p>{{ $summary['posture_reason'] ?? 'Posture is based on active incidents, assignments, needs, and data-quality signals.' }}</p>
                </article>
                <article>
                    <span>Primary Concern</span>
                    <strong>{{ $summary['primary_concern'] ?? 'No primary concern identified.' }}</strong>
                    <p>{{ $summary['confidence_note'] ?? 'Generated from available incident records.' }}</p>
                </article>
                <article>
                    <span>Current Areas</span>
                    <strong>{{ $summary['hotspot_area'] ?? 'No hotspot identified' }}</strong>
                    <p>{{ $summary['hotspot_note'] ?? 'Dominant type: '.($summary['dominant_incident_type'] ?? 'Unclassified') }}</p>
                </article>
            </div>
        @endif

        @if (! empty($situation['decision_points'] ?? []))
            <div class="sitrep-watch">
                <h3>Decision Points</h3>
                @foreach ($situation['decision_points'] as $point)
                    <p><strong>{{ $point['title'] ?? 'Decision point' }}:</strong> {{ $point['body'] ?? '' }}</p>
                @endforeach
            </div>
        @endif

        @if (! empty($situation['current_operating_picture'] ?? []))
            @php($picture = $situation['current_operating_picture'])
            <p class="sitrep-source-counts">
                <strong>Current totals:</strong>
                {{ $picture['open_reports'] ?? 0 }} open reports
                · {{ $picture['active_reports'] ?? 0 }} active
                · {{ $picture['deferred_reports'] ?? 0 }} deferred
                · {{ $picture['current_assignments'] ?? 0 }} assignments
                · {{ $picture['current_resource_units'] ?? 0 }} requested resource units
            </p>
        @endif

    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Situation</p>
            <h2>Current Areas of Concern</h2>
        </div>
        <p class="sitrep-narrative">{{ $situation['narrative'] ?? 'No situation narrative is available.' }}</p>
        @if (! empty($situation['concern_groups'] ?? []))
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Grouped Current Concerns',
                'tableClass' => 'is-concern-groups',
                'headers' => ['Concern', 'Open Reports', 'Areas', 'Main Signals', 'Teams', 'Resources'],
                'rows' => collect($situation['concern_groups'] ?? [])->map(fn ($row) => [
                    $row['concern'] ?? 'Current concern',
                    $row['open_reports'] ?? 0,
                    implode(', ', $row['areas'] ?? []),
                    $row['main_signals'] ?? '',
                    $row['current_assignments'] ?? 0,
                    $row['resource_units'] ?? 0,
                ])->all(),
                'empty' => 'No grouped current concerns available.',
            ])
            <p class="sitrep-note">Individual incident references are retained in the source snapshot and supporting tables.</p>
        @elseif (! empty($situation['areas_of_concern'] ?? []))
            <div class="sitrep-fact-list">
                @foreach ($situation['areas_of_concern'] as $area)
                    <article class="sitrep-fact">
                        <span>#{{ str_pad((string) ($area['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT) }} · {{ $area['status'] ?? 'Open' }}</span>
                        <strong>{{ $area['area'] ?? 'Area of concern' }}</strong>
                        <p>{{ $area['summary'] ?? '' }}</p>
                    </article>
                @endforeach
            </div>
        @endif
        <div class="sitrep-two-column">
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Current Locations',
                'headers' => ['Area', 'Incidents'],
                'rows' => collect($situation['locations'] ?? [])->map(fn ($row) => [$row['area'] ?? 'Unknown', $row['count'] ?? 0])->all(),
                'empty' => 'No location distribution available.',
            ])
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Current Incident Types',
                'headers' => ['Type', 'Mentions'],
                'rows' => collect($situation['incident_types'] ?? [])->map(fn ($row) => [$row['type'] ?? 'Unclassified', $row['count'] ?? 0])->all(),
                'empty' => 'No incident type distribution available.',
            ])
        </div>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Damage</p>
            <h2>Reported Damage</h2>
        </div>
        @if (! empty($damage['damage_groups'] ?? []))
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Damage Summary',
                'headers' => ['Damage Type', 'Reports', 'Severity / Signal', 'Affected Assets'],
                'rows' => collect($damage['damage_groups'] ?? [])->map(fn ($row) => [
                    $row['damage_type'] ?? 'Reported damage',
                    $row['reports'] ?? 0,
                    $row['severity_signal'] ?? '',
                    $row['affected_assets'] ?? '',
                ])->all(),
                'empty' => $damage['empty_state'] ?? 'No damage entries available.',
            ])
            <p class="sitrep-note">Individual damage entries are retained in the source snapshot and exports.</p>
        @else
            @include('pages.sitrep.partials.fact-list', [
                'items' => $damage['items'] ?? [],
                'empty' => $damage['empty_state'] ?? 'No damage entries available.',
            ])
        @endif
        <p class="sitrep-note">{{ $damage['confidence_note'] ?? '' }}</p>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Population</p>
            <h2>Affected People</h2>
        </div>
        <div class="sitrep-metrics is-compact">
            <div class="sitrep-metric">
                <span>Citizens assisted</span>
                <strong>{{ $population['citizens_assisted'] ?? $population['callers_assisted'] ?? 0 }}</strong>
            </div>
            <div class="sitrep-metric">
                <span>Current records</span>
                <strong>{{ $population['record_count'] ?? count($population['items'] ?? []) }}</strong>
            </div>
        </div>
        @if (! empty($population['numeric_total_note'] ?? null))
            <p class="sitrep-note">{{ $population['numeric_total_note'] }}</p>
        @endif
        @if (! empty($population['population_groups'] ?? []))
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Population Summary',
                'headers' => ['Population Signal', 'Reports', 'People / Families', 'Notes'],
                'rows' => collect($population['population_groups'] ?? [])->map(fn ($row) => [
                    $row['population_signal'] ?? 'Population signal',
                    $row['reports'] ?? 0,
                    $row['people_families'] ?? '',
                    $row['notes'] ?? '',
                ])->all(),
                'empty' => $population['empty_state'] ?? 'No population entries available.',
            ])
            @php($populationBreakdownRows = collect($population['population_groups'] ?? [])->flatMap(fn ($group) => collect($group['breakdowns'] ?? [])->map(fn ($row) => [
                $group['population_signal'] ?? 'Population signal',
                $row['breakdown'] ?? 'Breakdown',
                $row['count'] ?? 0,
            ]))->values()->all())
            @if (! empty($populationBreakdownRows))
                @include('pages.sitrep.partials.simple-table', [
                    'title' => 'Declared Member Breakdown',
                    'headers' => ['Population Signal', 'Breakdown', 'Count'],
                    'rows' => $populationBreakdownRows,
                    'empty' => 'No member breakdowns declared.',
                ])
            @endif
            <p class="sitrep-note">Individual population entries are retained in the source snapshot and exports.</p>
        @else
            @include('pages.sitrep.partials.fact-list', [
                'items' => $population['items'] ?? [],
                'empty' => $population['empty_state'] ?? 'No population entries available.',
            ])
        @endif
        <p class="sitrep-note">{{ $population['confidence_note'] ?? '' }}</p>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Actions</p>
            <h2>Response Posture</h2>
        </div>
        @include('pages.sitrep.partials.simple-table', [
            'title' => 'Team Deployment',
            'headers' => ['Category', 'Team', 'Requested', 'Assigned', 'Accepted', 'En Route', 'On Scene', 'Completed', 'Cancelled'],
            'rows' => collect($actions['deployment_groups'] ?? [])->map(fn ($row) => [
                $row['category'] ?? 'Uncategorized',
                $row['team'] ?? 'Team',
                ($row['status_counts']['requested'] ?? 0) ?: '',
                ($row['status_counts']['assigned'] ?? 0) ?: '',
                ($row['status_counts']['accepted'] ?? 0) ?: '',
                ($row['status_counts']['en_route'] ?? 0) ?: '',
                ($row['status_counts']['on_scene'] ?? 0) ?: '',
                ($row['status_counts']['completed'] ?? 0) ?: '',
                ($row['status_counts']['cancelled'] ?? 0) ?: '',
            ])->all(),
            'tableClass' => 'is-team-deployment',
            'empty' => 'No team assignments recorded.',
        ])
        @include('pages.sitrep.partials.simple-table', [
            'title' => 'Assignment Timing',
            'headers' => ['Incident', 'Team', 'Status', 'Accepted', 'En Route', 'On Scene', 'Completed', 'Cancelled', 'Time in Status'],
            'rows' => collect($actions['timing_rows'] ?? [])->map(fn ($row) => [
                '#'.str_pad((string) ($row['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT),
                $row['team'] ?? 'Team',
                $row['current_status'] ?? '',
                $row['assigned_to_accepted'] ?? '',
                $row['accepted_to_en_route'] ?? '',
                $row['en_route_to_on_scene'] ?? '',
                $row['on_scene_to_completed'] ?? '',
                $row['assigned_to_cancelled'] ?? '',
                $row['elapsed_time'] ?? '',
            ])->all(),
            'tableClass' => 'is-assignment-timing',
            'empty' => 'No assignment timing milestones recorded.',
        ])
        <p class="sitrep-note">Timing rows are scenario-specific and derived from team assignment milestone timestamps. Time in Status shows how long an open assignment has been in its current status, falling back to assignment time when older records do not have the milestone timestamp.</p>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Needs</p>
            <h2>Current Resource Posture</h2>
        </div>
        @if (! empty($needs['category_groups'] ?? []))
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Category Demand',
                'headers' => ['Category', 'Quantity', 'Resources'],
                'rows' => collect($needs['category_groups'] ?? [])->map(fn ($row) => [$row['category'] ?? 'Uncategorized', $row['quantity_requested'] ?? 0, implode(', ', $row['resources'] ?? [])])->all(),
                'empty' => 'No category demand available.',
            ])
        @endif
        @include('pages.sitrep.partials.simple-table', [
            'title' => 'Resource Needs',
            'headers' => ['Resource', 'Category', 'Quantity', 'Incidents'],
            'rows' => collect($needs['items'] ?? [])->map(fn ($row) => [$row['resource'] ?? 'Resource', $row['category'] ?? 'Uncategorized', $row['quantity_requested'] ?? 0, $row['incident_count'] ?? 0])->all(),
            'empty' => $needs['empty_state'] ?? 'No structured resource needs recorded.',
        ])
        <p class="sitrep-note">{{ $needs['confidence_note'] ?? '' }}</p>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Gaps</p>
            <h2>{{ $gaps['title'] ?? 'Response Constraints and Confidence Gaps' }}</h2>
        </div>
        @if (! empty($gaps['intro'] ?? null))
            <p class="sitrep-narrative">{{ $gaps['intro'] }}</p>
        @endif
        @forelse (($gaps['items'] ?? []) as $gap)
            <article class="sitrep-gap">
                @if (! empty($gap['category'] ?? null))
                    <span>{{ $gap['category'] }}</span>
                @endif
                <strong>{{ $gap['title'] ?? 'Gap' }}</strong>
                @if (! empty($gap['decision_relevance'] ?? null))
                    <p>{{ $gap['decision_relevance'] }}</p>
                @elseif (! empty($gap['body'] ?? null))
                    <p>{{ $gap['body'] }}</p>
                @endif
                @if (! empty($gap['evidence'] ?? null))
                    <dl class="sitrep-gap-details">
                        <div>
                            <dt>Evidence</dt>
                            <dd>
                                {{ $gap['evidence'] }}
                            </dd>
                        </div>
                        @if (! empty($gap['confidence_note'] ?? null))
                            <div>
                                <dt>Confidence</dt>
                                <dd>{{ $gap['confidence_note'] }}</dd>
                            </div>
                        @endif
                    </dl>
                @endif
                @if (! empty($gap['items'] ?? []))
                    <ul class="sitrep-gap-evidence">
                        @foreach ($gap['items'] as $detail)
                            <li>
                                <strong>{{ $detail['status'] ?? 'Reported' }}</strong>
                                {{ $detail['route_location'] ?? 'Location not specified' }}
                                @if (! empty($detail['obstruction_type'] ?? null))
                                    — {{ $detail['obstruction_type'] }}
                                @endif
                                @if (! empty($detail['cleared'] ?? null))
                                    <span>Cleared: {{ $detail['cleared'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @empty
            <p class="sitrep-empty">{{ $gaps['empty_state'] ?? 'No gaps identified.' }}</p>
        @endforelse
    </section>

    @if (! empty($situation['period_activity'] ?? []))
        @php($activity = $situation['period_activity'])
        <section class="sitrep-section">
            <div class="sitrep-section-head">
                <p class="sitrep-eyebrow">Period Activity</p>
                <h2>Report Status History</h2>
            </div>
            <div class="sitrep-metrics is-compact">
                <div class="sitrep-metric">
                    <span>Total reports</span>
                    <strong>{{ $activity['total_reports'] ?? 0 }}</strong>
                </div>
                <div class="sitrep-metric">
                    <span>Open at close</span>
                    <strong>{{ $activity['open_at_close'] ?? 0 }}</strong>
                </div>
                <div class="sitrep-metric">
                    <span>Resolved</span>
                    <strong>{{ $activity['resolved_during_period'] ?? 0 }}</strong>
                </div>
                <div class="sitrep-metric">
                    <span>Discarded / excluded</span>
                    <strong>{{ $activity['discarded_excluded'] ?? 0 }}</strong>
                </div>
            </div>
            <p class="sitrep-note">{{ $activity['note'] ?? '' }}</p>
        </section>
    @endif

    @if (! empty($situation['verification_notes'] ?? []))
        <section class="sitrep-section">
            <div class="sitrep-section-head">
                <p class="sitrep-eyebrow">Verification</p>
                <h2>Verification Notes</h2>
            </div>
            <div class="sitrep-watch">
                @foreach ($situation['verification_notes'] as $note)
                    <p>{{ $note }}</p>
                @endforeach
            </div>
        </section>
    @endif

    <footer class="sitrep-footer">
        <div>
            <strong>Data Quality</strong>
            <p>{{ $dataQuality['global_note'] ?? 'Generated from current Hotline data.' }}</p>
        </div>
        <div>
            <strong>Privacy Defaults</strong>
            <p>{{ implode(', ', array_map(fn ($key, $value) => str_replace('_', ' ', $key).': '.$value, array_keys($redactions), $redactions)) }}</p>
        </div>
        <div>
            <strong>Source Snapshot</strong>
            <p class="sitrep-source-lines">
                <span>
                    Hotline: {{ $hotlineVersionLabel }}
                    @if (! empty($hotlineBuildLabel))
                        · Build {{ $hotlineBuildLabel }}
                    @endif
                </span>
                @if ($hubNameLabel)
                    <span>
                        Hub Node: {{ $hubNameLabel }}
                        @if ($deploymentLabel)
                            · {{ $deploymentLabel }}
                        @endif
                        @if (! empty($hubNode['relay_hub_id'] ?? null))
                            · {{ $hubNode['relay_hub_id'] }}
                        @endif
                    </span>
                @endif
                @if ($primaryUplinkLabel)
                    <span>Uplink: {{ $primaryUplinkLabel }}</span>
                @endif
            </p>
        </div>
    </footer>
</article>
