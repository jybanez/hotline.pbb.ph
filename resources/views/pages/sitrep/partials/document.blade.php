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

    $metricLabels = [
        'total_incidents' => 'Incidents',
        'total_call_sessions' => 'Call sessions',
        'multi_call_incidents' => 'Multi-call incidents',
        'incident_type_mentions' => 'Type mentions',
        'team_assignments' => 'Assignments',
        'resource_need_units' => 'Resource units',
        'new_this_period' => 'New',
        'carried_over' => 'Carried over',
        'closed_this_period' => 'Closed',
        'active_at_close' => 'Active at close',
    ];

    $summaryMetrics = $summary['supporting_metrics'] ?? [];
    $metricGroups = [
        'Incident Flow' => ['carried_over', 'new_this_period', 'active_at_close', 'closed_this_period'],
        'Operational Load' => ['total_incidents', 'team_assignments', 'resource_need_units'],
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
    $periodLabel = $sitrep->period_started_at && $sitrep->period_ended_at
        ? ($sitrep->period_started_at->isSameDay($sitrep->period_ended_at)
            ? $sitrep->period_started_at->format('M d, Y')
            : $sitrep->period_started_at->format('M d, Y').' - '.$sitrep->period_ended_at->format('M d, Y'))
        : 'Reporting period';
@endphp

<article class="sitrep-document">
    @if ($isPreview && ! $sitrep->isPubliclyVisible())
        <div class="sitrep-preview-banner">Preview only. This SITREP is not public unless status is published and visibility is public.</div>
    @endif

    <header class="sitrep-header">
        <div>
            <p class="sitrep-eyebrow">PBB Hotline Periodic SITREP</p>
            <h1>{{ $visualTitle }}</h1>
            <p class="sitrep-periodline">{{ $periodLabel }}</p>
            <p class="sitrep-headline">{{ $summary['headline'] ?? 'Situation report generated from Hotline incident records.' }}</p>
        </div>
        <dl class="sitrep-meta">
            <div>
                <dt>Report</dt>
                <dd>#{{ str_pad((string) $sitrep->sequence_number, 4, '0', STR_PAD_LEFT) }}</dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd>{{ ucfirst($sitrep->status) }} / {{ ucfirst($sitrep->visibility) }}</dd>
            </div>
            <div>
                <dt>Alert</dt>
                <dd>{{ $sitrep->alert_level ?? 'Normal' }}</dd>
            </div>
            <div>
                <dt>Coverage</dt>
                <dd>{{ $sitrep->coverage_area ?? 'PBB Hotline Coverage Area' }}</dd>
            </div>
            <div>
                <dt>Period</dt>
                <dd>{{ $sitrep->period_started_at?->format('M d, Y g:i A') }} - {{ $sitrep->period_ended_at?->format('M d, Y g:i A') }}</dd>
            </div>
            <div>
                <dt>Generated</dt>
                <dd>{{ $sitrep->generated_at?->format('M d, Y g:i A') }}</dd>
            </div>
        </dl>
    </header>

    <section class="sitrep-section sitrep-summary">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Summary</p>
            <h2>Current Situation Picture</h2>
        </div>
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
                <span>Hotspot</span>
                <strong>{{ $summary['hotspot_area'] ?? 'No hotspot identified' }}</strong>
                <p>Dominant type: {{ $summary['dominant_incident_type'] ?? 'Unclassified' }}</p>
            </article>
        </div>

        <div class="sitrep-metric-groups">
            @foreach ($metricGroups as $groupLabel => $keys)
                @php($visibleKeys = array_values(array_filter($keys, fn ($key) => array_key_exists($key, $summaryMetrics))))
                @continue(empty($visibleKeys))
                <section class="sitrep-metric-group" aria-label="{{ $groupLabel }}">
                    <h3>{{ $groupLabel }}</h3>
                    <div class="sitrep-metrics">
                        @foreach ($visibleKeys as $key)
                            <div class="sitrep-metric">
                                <span>{{ $metricLabels[$key] ?? ucfirst(str_replace('_', ' ', $key)) }}</span>
                                <strong>{{ $summaryMetrics[$key] }}</strong>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            @if (! empty($otherMetrics))
                <section class="sitrep-metric-group" aria-label="Other Metrics">
                    <h3>Other Metrics</h3>
                    <div class="sitrep-metrics">
                        @foreach ($otherMetrics as $key => $value)
                            <div class="sitrep-metric">
                                <span>{{ $metricLabels[$key] ?? ucfirst(str_replace('_', ' ', $key)) }}</span>
                                <strong>{{ $value }}</strong>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <div class="sitrep-watch">
            <h3>Watch Items</h3>
            @forelse (($summary['priority_watch_items'] ?? []) as $item)
                <p>{{ $item }}</p>
            @empty
                <p>No watch items were identified from available data.</p>
            @endforelse
        </div>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Situation</p>
            <h2>What And Where</h2>
        </div>
        <p class="sitrep-narrative">{{ $situation['narrative'] ?? 'No situation narrative is available.' }}</p>
        <div class="sitrep-two-column">
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Locations',
                'headers' => ['Area', 'Incidents'],
                'rows' => collect($situation['locations'] ?? [])->map(fn ($row) => [$row['area'] ?? 'Unknown', $row['count'] ?? 0])->all(),
                'empty' => 'No location distribution available.',
            ])
            @include('pages.sitrep.partials.simple-table', [
                'title' => 'Incident Types',
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
        @include('pages.sitrep.partials.fact-list', [
            'items' => $damage['items'] ?? [],
            'empty' => $damage['empty_state'] ?? 'No damage entries available.',
        ])
        <p class="sitrep-note">{{ $damage['confidence_note'] ?? '' }}</p>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Population</p>
            <h2>Affected People</h2>
        </div>
        <div class="sitrep-metrics is-compact">
            <div class="sitrep-metric">
                <span>Callers assisted</span>
                <strong>{{ $population['callers_assisted'] ?? 0 }}</strong>
            </div>
            <div class="sitrep-metric">
                <span>Reported numeric total</span>
                <strong>{{ $population['numeric_total'] ?? 0 }}</strong>
            </div>
        </div>
        @include('pages.sitrep.partials.fact-list', [
            'items' => $population['items'] ?? [],
            'empty' => $population['empty_state'] ?? 'No population entries available.',
        ])
        <p class="sitrep-note">{{ $population['confidence_note'] ?? '' }}</p>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Actions</p>
            <h2>Operational Response</h2>
        </div>
        @include('pages.sitrep.partials.simple-table', [
            'title' => 'Team Assignments',
            'headers' => ['Incident', 'Team', 'Status'],
            'rows' => collect($actions['assignments'] ?? [])->map(fn ($row) => ['#'.str_pad((string) ($row['incident_id'] ?? ''), 6, '0', STR_PAD_LEFT), $row['team'] ?? 'Team', $statusLabel($row['status_label'] ?? $row['status'] ?? 'unknown')])->all(),
            'empty' => 'No team assignments recorded.',
        ])
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Needs</p>
            <h2>Requested Resources</h2>
        </div>
        @include('pages.sitrep.partials.simple-table', [
            'title' => 'Resource Needs',
            'headers' => ['Resource', 'Quantity', 'Incidents', 'Posture'],
            'rows' => collect($needs['items'] ?? [])->map(fn ($row) => [$row['resource'] ?? 'Resource', $row['quantity_requested'] ?? 0, $row['incident_count'] ?? 0, $row['status'] ?? 'open'])->all(),
            'empty' => $needs['empty_state'] ?? 'No structured resource needs recorded.',
        ])
        <p class="sitrep-note">{{ $needs['confidence_note'] ?? '' }}</p>
    </section>

    <section class="sitrep-section">
        <div class="sitrep-section-head">
            <p class="sitrep-eyebrow">Gaps</p>
            <h2>Blockers And Data Gaps</h2>
        </div>
        @forelse (($gaps['items'] ?? []) as $gap)
            <article class="sitrep-gap">
                <strong>{{ $gap['title'] ?? 'Gap' }}</strong>
                <p>{{ $gap['body'] ?? '' }}</p>
            </article>
        @empty
            <p class="sitrep-empty">{{ $gaps['empty_state'] ?? 'No gaps identified.' }}</p>
        @endforelse
    </section>

    <footer class="sitrep-footer">
        <div>
            <strong>Data Quality</strong>
            <p>{{ $dataQuality['global_note'] ?? 'Generated from current Hotline data.' }}</p>
        </div>
        <div>
            <strong>Privacy Defaults</strong>
            <p>{{ implode(', ', array_map(fn ($key, $value) => str_replace('_', ' ', $key).': '.$value, array_keys($redactions), $redactions)) }}</p>
        </div>
    </footer>
</article>
