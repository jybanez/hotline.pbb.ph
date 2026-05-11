<?php

namespace App\Support\Sitreps;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentTypeDetail;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Domain\Users\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SitrepGenerationService
{
    public function generate(User $preparedBy, array $input): SitrepReport
    {
        $periodStart = Carbon::parse($input['period_started_at'])->startOfSecond();
        $periodEnd = Carbon::parse($input['period_ended_at'])->startOfSecond();
        $coverageArea = trim((string) ($input['coverage_area'] ?? 'PBB Hotline Coverage Area'));
        $visibility = (string) ($input['visibility'] ?? 'private');
        $status = (string) ($input['status'] ?? 'draft');
        $publishNow = (bool) ($input['publish'] ?? false);

        if ($publishNow) {
            $status = 'published';
            $visibility = 'public';
        }

        $incidents = $this->scopedIncidents($preparedBy, $periodStart, $periodEnd);
        $context = $this->buildContext($incidents, $periodStart, $periodEnd);
        $summary = $this->buildSummary($context, $periodStart, $periodEnd, $coverageArea);
        $situation = $this->buildSituation($context);
        $damage = $this->buildDamage($context);
        $population = $this->buildPopulation($context);
        $actions = $this->buildActions($context);
        $needs = $this->buildNeeds($context);
        $gaps = $this->buildGaps($context);
        $dataQuality = $this->buildDataQuality($context);

        $sequence = (int) SitrepReport::query()->max('sequence_number') + 1;
        $generatedAt = now();

        return SitrepReport::query()->create([
            'sequence_number' => $sequence,
            'title' => $input['title'] ?? sprintf('PBB Hotline SITREP #%04d', $sequence),
            'coverage_area' => $coverageArea,
            'period_started_at' => $periodStart,
            'period_ended_at' => $periodEnd,
            'generated_at' => $generatedAt,
            'published_at' => $status === 'published' ? $generatedAt : null,
            'status' => $status,
            'visibility' => $visibility,
            'alert_level' => $summary['posture'] === 'critical' ? 'Critical' : ($summary['posture'] === 'strained' ? 'Elevated' : 'Normal'),
            'prepared_by_user_id' => $preparedBy->id,
            'reviewed_by_user_id' => null,
            'summary_json' => $summary,
            'situation_json' => $situation,
            'damage_json' => $damage,
            'population_json' => $population,
            'actions_json' => $actions,
            'needs_json' => $needs,
            'gaps_json' => $gaps,
            'source_snapshot_json' => $this->buildSourceSnapshot($context, $periodStart, $periodEnd),
            'privacy_redactions_json' => $this->buildPrivacyRedactions(),
            'data_quality_json' => $dataQuality,
        ]);
    }

    private function scopedIncidents(User $preparedBy, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $query = Incident::query()
            ->with([
                'caller',
                'callSessions',
                'incidentTypes.category',
                'incidentTypeDetails.incidentType',
                'incidentResourcesNeeded.resourceType',
                'teamAssignments.team',
                'callerLocations',
            ]);

        if ($preparedBy->role === UserRole::Operator) {
            $query->where('operator_id', $preparedBy->id);
        }

        return $query
            ->where(function ($query) use ($periodStart, $periodEnd): void {
                $query
                    ->whereBetween('called_at', [$periodStart, $periodEnd])
                    ->orWhereBetween('created_at', [$periodStart, $periodEnd])
                    ->orWhereBetween('updated_at', [$periodStart, $periodEnd])
                    ->orWhereBetween('resolved_at', [$periodStart, $periodEnd])
                    ->orWhereIn('status', [
                        IncidentStatus::Active->value,
                        IncidentStatus::Deferred->value,
                    ]);
            })
            ->orderBy('id')
            ->get();
    }

    private function buildContext(Collection $incidents, Carbon $periodStart, Carbon $periodEnd): array
    {
        $statusCounts = $incidents
            ->countBy(fn (Incident $incident) => $this->statusValue($incident->status))
            ->all();

        $callSessions = $incidents->flatMap(fn (Incident $incident) => $incident->callSessions);
        $teamAssignments = $incidents->flatMap(fn (Incident $incident) => $incident->teamAssignments);
        $resourceNeeds = $incidents->flatMap(fn (Incident $incident) => $incident->incidentResourcesNeeded);
        $fieldDetails = $incidents->flatMap(fn (Incident $incident) => $incident->incidentTypeDetails);
        $callerLocations = $incidents->flatMap(fn (Incident $incident) => $incident->callerLocations);

        $typeRows = collect();
        foreach ($incidents as $incident) {
            foreach ($incident->incidentTypes as $type) {
                $typeRows->push([
                    'incident_id' => $incident->id,
                    'type_id' => $type->id,
                    'name' => $type->name,
                    'category' => $type->category?->name,
                ]);
            }
        }

        $resourceRows = $resourceNeeds
            ->map(fn ($need) => [
                'incident_id' => $need->incident_id,
                'resource_type_id' => $need->resource_type_id,
                'name' => $need->resourceType?->name ?? 'Resource',
                'unit' => $need->resourceType?->unit_label,
                'quantity' => (int) $need->quantity_required,
                'notes' => $need->notes,
                'status' => $this->statusValue($need->incident?->status),
            ])
            ->values();

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'incidents' => $incidents,
            'status_counts' => $statusCounts,
            'call_sessions' => $callSessions,
            'team_assignments' => $teamAssignments,
            'resource_needs' => $resourceRows,
            'field_details' => $fieldDetails,
            'citizen_locations' => $callerLocations,
            'type_rows' => $typeRows,
            'type_counts' => $typeRows->countBy('name')->sortDesc(),
            'location_counts' => $incidents
                ->countBy(fn (Incident $incident) => $this->areaLabel($incident))
                ->sortDesc(),
            'new_incident_count' => $incidents->filter(fn (Incident $incident) => $incident->created_at?->betweenIncluded($periodStart, $periodEnd))->count(),
            'carried_over_incident_count' => $incidents->filter(fn (Incident $incident) => $incident->created_at && $incident->created_at->lt($periodStart) && in_array($this->statusValue($incident->status), [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))->count(),
            'closed_incident_count' => $incidents
                ->filter(fn (Incident $incident) => in_array($this->statusValue($incident->status), [IncidentStatus::Resolved->value, IncidentStatus::Discarded->value], true))
                ->filter(fn (Incident $incident) => $incident->resolved_at?->betweenIncluded($periodStart, $periodEnd) || $incident->updated_at?->betweenIncluded($periodStart, $periodEnd))
                ->count(),
            'active_at_close_incident_count' => $incidents->filter(fn (Incident $incident) => in_array($this->statusValue($incident->status), [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))->count(),
        ];
    }

    private function buildSummary(array $context, Carbon $periodStart, Carbon $periodEnd, string $coverageArea): array
    {
        $incidents = $context['incidents'];
        $dominantType = $context['type_counts']->keys()->first() ?? 'Unclassified incidents';
        $hotspot = $context['location_counts']->keys()->first() ?? $coverageArea;
        $activeCount = (int) ($context['status_counts'][IncidentStatus::Active->value] ?? 0);
        $deferredCount = (int) ($context['status_counts'][IncidentStatus::Deferred->value] ?? 0);
        $blockedAssignments = $context['team_assignments']
            ->filter(fn ($assignment) => ! in_array((string) $assignment->status, ['completed', 'cancelled'], true))
            ->count();
        $criticalNeeds = $context['resource_needs']
            ->filter(fn (array $need) => in_array($need['status'], [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))
            ->sum('quantity');
        $missingLocations = $incidents
            ->filter(fn (Incident $incident) => $incident->latitude === null || $incident->longitude === null)
            ->count();

        $posture = match (true) {
            $criticalNeeds > 0 || $blockedAssignments >= 3 => 'strained',
            ($activeCount + $deferredCount) > 0 => 'monitoring',
            default => 'stable',
        };

        $watchItems = collect([
            $missingLocations > 0 ? sprintf('Citizen location unavailable for %d incident%s.', $missingLocations, $missingLocations === 1 ? '' : 's') : null,
            $blockedAssignments > 0 ? sprintf('%d assignment%s still open.', $blockedAssignments, $blockedAssignments === 1 ? '' : 's') : null,
            $criticalNeeds > 0 ? sprintf('%d requested resource unit%s still need review.', $criticalNeeds, $criticalNeeds === 1 ? '' : 's') : null,
        ])->filter()->values()->all();

        return [
            'headline' => sprintf('%s activity concentrated in %s', $dominantType, $hotspot),
            'posture' => $posture,
            'posture_label' => ucfirst($posture),
            'posture_reason' => $this->postureReason($posture, $activeCount, $deferredCount, $blockedAssignments, $criticalNeeds),
            'dominant_incident_type' => $dominantType,
            'hotspot_area' => $hotspot,
            'primary_concern' => $watchItems[0] ?? 'No immediate operational blocker identified.',
            'priority_watch_items' => $watchItems,
            'key_change_since_previous' => 'No previous SITREP comparison is available in this first pass.',
            'supporting_metrics' => [
                'total_incidents' => $incidents->count(),
                'total_call_sessions' => $context['call_sessions']->count(),
                'multi_call_incidents' => $incidents->filter(fn (Incident $incident) => $incident->callSessions->count() > 1)->count(),
                'incident_type_mentions' => $context['type_rows']->count(),
                'team_assignments' => $context['team_assignments']->count(),
                'resource_need_units' => $context['resource_needs']->sum('quantity'),
                'new_this_period' => $context['new_incident_count'],
                'carried_over' => $context['carried_over_incident_count'],
                'closed_this_period' => $context['closed_incident_count'],
                'active_at_close' => $context['active_at_close_incident_count'],
            ],
            'status_counts' => $context['status_counts'],
            'period_label' => sprintf('%s to %s', $periodStart->toDayDateTimeString(), $periodEnd->toDayDateTimeString()),
            'confidence_note' => $missingLocations > 0
                ? 'Some incident location data is missing; summary geography may be incomplete.'
                : 'Summary is based on available incident, assignment, resource, and call-session records.',
        ];
    }

    private function buildSituation(array $context): array
    {
        $total = $context['incidents']->count();
        $dominantType = $context['type_counts']->keys()->first() ?? 'unclassified incidents';
        $hotspot = $context['location_counts']->keys()->first() ?? 'unmapped areas';

        return [
            'narrative' => $total > 0
                ? sprintf('Hotline recorded %d incident%s in scope. The dominant classification is %s, with most activity associated with %s.', $total, $total === 1 ? '' : 's', $dominantType, $hotspot)
                : 'No incidents are included in this reporting period.',
            'locations' => $this->countMapRows($context['location_counts'], 'area'),
            'incident_types' => $this->countMapRows($context['type_counts'], 'type'),
            'multi_type_incident_count' => $context['incidents']->filter(fn (Incident $incident) => $incident->incidentTypes->count() > 1)->count(),
            'notable_events' => $this->notableEvents($context),
            'confidence_note' => 'Incident type buckets are type mentions; totals can exceed total incidents when an incident has multiple types.',
        ];
    }

    private function buildDamage(array $context): array
    {
        $items = $this->detailsForSection($context['field_details'], 'damage');

        return [
            'items' => $items,
            'confirmed_count' => 0,
            'reported_count' => count($items),
            'unverified_count' => count($items),
            'empty_state' => count($items) === 0 ? 'No damage fields have been reported for this period.' : null,
            'confidence_note' => count($items) > 0 ? 'Damage entries are reported from incident type fields and should be verified before external release.' : 'No configured/reported damage values were found.',
        ];
    }

    private function buildPopulation(array $context): array
    {
        $items = $this->detailsForSection($context['field_details'], 'population');
        $numericTotal = collect($items)
            ->sum(fn (array $item) => is_numeric($item['value']) ? (float) $item['value'] : 0);

        $assisted = $context['incidents']->pluck('citizen_id')->filter()->unique()->count();

        return [
            'citizens_assisted' => $assisted,
            'items' => $items,
            'numeric_total' => $numericTotal,
            'empty_state' => count($items) === 0 ? 'No population fields have been reported for this period.' : null,
            'confidence_note' => count($items) > 0 ? 'Population values are sourced from incident type fields.' : 'Population details are not reported or not configured.',
        ];
    }

    private function buildActions(array $context): array
    {
        $assignmentRows = $context['team_assignments']
            ->map(fn ($assignment) => [
                'incident_id' => $assignment->incident_id,
                'team' => $assignment->team?->name ?? 'Team',
                'status' => (string) $assignment->status,
                'status_label' => $this->statusLabel($assignment->status),
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'completed_at' => $assignment->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'assignment_status_counts' => $context['team_assignments']->countBy(fn ($assignment) => (string) $assignment->status)->all(),
            'assignments' => $assignmentRows,
            'call_session_count' => $context['call_sessions']->count(),
            'status_decisions' => $context['status_counts'],
            'transfer_activity' => [
                'count' => DB::table('incident_transfers')
                    ->whereIn('incident_id', $context['incidents']->pluck('id')->all())
                    ->count(),
            ],
        ];
    }

    private function buildNeeds(array $context): array
    {
        $rows = $context['resource_needs']
            ->groupBy('name')
            ->map(fn (Collection $items, string $name) => [
                'resource' => $name,
                'quantity_requested' => $items->sum('quantity'),
                'incident_count' => $items->pluck('incident_id')->unique()->count(),
                'unit' => $items->first()['unit'] ?? null,
                'status' => $items->contains(fn (array $item) => in_array($item['status'], [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))
                    ? 'open'
                    : 'closed',
            ])
            ->values()
            ->all();

        return [
            'items' => $rows,
            'total_quantity_requested' => collect($rows)->sum('quantity_requested'),
            'empty_state' => count($rows) === 0 ? 'No structured resource needs were requested in this period.' : null,
            'confidence_note' => 'Needs are derived from structured incident resources needed, not narrative fields.',
        ];
    }

    private function buildGaps(array $context): array
    {
        $items = [];

        $missingLocations = $context['incidents']
            ->filter(fn (Incident $incident) => $incident->latitude === null || $incident->longitude === null)
            ->count();

        if ($missingLocations > 0) {
            $items[] = [
                'type' => 'missing_location',
                'title' => 'Citizen location unavailable',
                'body' => sprintf('%d incident%s do not have citizen coordinates.', $missingLocations, $missingLocations === 1 ? '' : 's'),
                'public_visible' => true,
            ];
        }

        $openNeeds = $context['resource_needs']
            ->filter(fn (array $need) => in_array($need['status'], [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))
            ->sum('quantity');

        if ($openNeeds > 0) {
            $items[] = [
                'type' => 'open_needs',
                'title' => 'Resource needs still open',
                'body' => sprintf('%d requested resource unit%s remain tied to active/deferred incidents.', $openNeeds, $openNeeds === 1 ? '' : 's'),
                'public_visible' => true,
            ];
        }

        return [
            'items' => $items,
            'empty_state' => count($items) === 0 ? 'No operational gaps were identified from configured data.' : null,
        ];
    }

    private function buildDataQuality(array $context): array
    {
        $missingLocations = $context['incidents']
            ->filter(fn (Incident $incident) => $incident->latitude === null || $incident->longitude === null)
            ->count();
        $withoutTypes = $context['incidents']
            ->filter(fn (Incident $incident) => $incident->incidentTypes->isEmpty())
            ->count();
        $withoutAssignments = $context['incidents']
            ->filter(fn (Incident $incident) => $incident->teamAssignments->isEmpty())
            ->count();

        return [
            'global_note' => 'Generated from current Hotline incident data. Unconfigured fields and missing values are reported as data-quality notes.',
            'missing_citizen_location_count' => $missingLocations,
            'incidents_without_type_count' => $withoutTypes,
            'incidents_without_assignment_count' => $withoutAssignments,
            'unmapped_field_count' => $context['field_details']
                ->filter(fn (IncidentTypeDetail $detail) => $this->classifyDetail($detail) === 'situation')
                ->count(),
        ];
    }

    private function buildSourceSnapshot(array $context, Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            'period_started_at' => $periodStart->toIso8601String(),
            'period_ended_at' => $periodEnd->toIso8601String(),
            'incident_ids' => $context['incidents']->pluck('id')->values()->all(),
            'call_session_ids' => $context['call_sessions']->pluck('id')->values()->all(),
            'team_assignment_ids' => $context['team_assignments']->pluck('id')->values()->all(),
            'resource_need_ids' => $context['incidents']->flatMap(fn (Incident $incident) => $incident->incidentResourcesNeeded)->pluck('id')->values()->all(),
            'incident_type_detail_ids' => $context['field_details']->pluck('id')->values()->all(),
            'adapter_version' => 1,
            'counting_rule_version' => 1,
        ];
    }

    private function buildPrivacyRedactions(): array
    {
        return [
            'citizen_phone_numbers' => 'redacted',
            'raw_chat_transcript' => 'omitted',
            'exact_coordinates' => 'omitted from public report',
            'media_links' => 'omitted unless approved',
            'internal_operator_notes' => 'omitted',
        ];
    }

    private function detailsForSection(Collection $details, string $section): array
    {
        return $details
            ->filter(fn (IncidentTypeDetail $detail) => trim((string) $detail->field_value) !== '')
            ->filter(fn (IncidentTypeDetail $detail) => $this->classifyDetail($detail) === $section)
            ->map(fn (IncidentTypeDetail $detail) => [
                'incident_id' => $detail->incident_id,
                'incident_type_id' => $detail->incident_type_id,
                'label' => $detail->field_label,
                'key' => $detail->field_key,
                'value' => $detail->field_value,
                'unit' => $detail->unit,
                'confidence' => 'reported',
            ])
            ->values()
            ->all();
    }

    private function classifyDetail(IncidentTypeDetail $detail): string
    {
        $label = strtolower($detail->field_label.' '.$detail->field_key.' '.$detail->unit);
        $inputType = strtolower((string) $detail->input_type);

        if (str_contains($label, 'damage') || str_contains($label, 'destroy') || str_contains($label, 'loss')) {
            return 'damage';
        }

        if (
            str_contains($label, 'injured')
            || str_contains($label, 'missing')
            || str_contains($label, 'affected')
            || str_contains($label, 'evacuat')
            || str_contains($label, 'people')
            || str_contains($label, 'person')
            || str_contains($label, 'household')
        ) {
            return 'population';
        }

        if (
            str_contains($label, 'blocked')
            || str_contains($label, 'unavailable')
            || str_contains($label, 'constraint')
            || str_contains($label, 'unknown')
            || str_contains($label, 'gap')
        ) {
            return 'gaps';
        }

        if (str_contains($label, 'action') || str_contains($label, 'done') || str_contains($label, 'responded')) {
            return 'actions';
        }

        return $inputType === 'number' && preg_match('/people|person|household/', $label) ? 'population' : 'situation';
    }

    private function notableEvents(array $context): array
    {
        return $context['incidents']
            ->flatMap(function (Incident $incident): array {
                $events = [];

                if ($incident->called_at) {
                    $events[] = [
                        'incident_id' => $incident->id,
                        'label' => sprintf('Incident #%06d opened', $incident->id),
                        'occurred_at' => $incident->called_at->toIso8601String(),
                    ];
                }

                if ($incident->resolved_at) {
                    $events[] = [
                        'incident_id' => $incident->id,
                        'label' => sprintf('Incident #%06d closed as %s', $incident->id, $this->statusValue($incident->status)),
                        'occurred_at' => $incident->resolved_at->toIso8601String(),
                    ];
                }

                return $events;
            })
            ->sortBy('occurred_at')
            ->values()
            ->take(12)
            ->all();
    }

    private function countMapRows(Collection $counts, string $key): array
    {
        return $counts
            ->map(fn (int $count, string $label) => [
                $key => $label,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    private function postureReason(string $posture, int $activeCount, int $deferredCount, int $blockedAssignments, int $criticalNeeds): string
    {
        return match ($posture) {
            'strained' => sprintf('%d active, %d deferred, %d open assignments, and %d requested resource units require attention.', $activeCount, $deferredCount, $blockedAssignments, $criticalNeeds),
            'monitoring' => sprintf('%d active and %d deferred incidents remain under monitoring.', $activeCount, $deferredCount),
            default => 'No active operational blocker was identified in the generated snapshot.',
        };
    }

    private function areaLabel(Incident $incident): string
    {
        return $incident->location_barangay
            ?: $incident->location_citymunicipality
            ?: $incident->location
            ?: 'Unmapped area';
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    private function statusLabel(mixed $status): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $this->statusValue($status)));

        if ($value === '') {
            return 'Unknown';
        }

        return collect(explode(' ', $value))
            ->filter()
            ->map(fn (string $part) => ucfirst(strtolower($part)))
            ->implode(' ');
    }
}
