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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SitrepGenerationService
{
    private const HUB_NODE_CACHE_KEY = 'pbb_hotline.relay_hub_json.last_successful_snapshot';

    public function __construct(
        private readonly SitrepRelayOutboxService $relayOutbox,
    ) {
    }

    public function generate(?User $preparedBy, array $input): SitrepReport
    {
        $periodStart = Carbon::parse($input['period_started_at'])->startOfSecond();
        $periodEnd = Carbon::parse($input['period_ended_at'])->startOfSecond();
        $systemGenerated = (bool) ($input['system_generated'] ?? false);
        $hubNodeSnapshot = $this->buildHubNodeSnapshot();
        $coverageArea = $systemGenerated
            ? $this->coverageAreaFromHubNode($hubNodeSnapshot)
            : trim((string) ($input['coverage_area'] ?? 'PBB Hotline Coverage Area'));
        $visibility = (string) ($input['visibility'] ?? 'private');
        $status = (string) ($input['status'] ?? 'draft');
        $publishNow = (bool) ($input['publish'] ?? false);

        if ($publishNow) {
            $status = 'published';
            $visibility = 'public';
        }

        $incidents = $this->scopedIncidents($preparedBy, $periodStart, $periodEnd);
        $context = $this->buildContext($incidents, $periodStart, $periodEnd);
        $generatedAt = now();
        $summary = $this->buildSummary($context, $periodStart, $periodEnd, $coverageArea);
        $situation = $this->buildSituation($context);
        $damage = $this->buildDamage($context);
        $population = $this->buildPopulation($context);
        $actions = $this->buildActions($context, $generatedAt);
        $needs = $this->buildNeeds($context);
        $gaps = $this->buildGaps($context);
        $dataQuality = $this->buildDataQuality($context);
        $sourceSnapshot = $this->buildSourceSnapshot($context, $periodStart, $periodEnd, $hubNodeSnapshot, $systemGenerated, $preparedBy);
        $location = SitrepPayloadSchema::locationFromSourceSnapshot($sourceSnapshot);

        $sequence = (int) SitrepReport::query()->max('sequence_number') + 1;

        $report = SitrepReport::query()->create([
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
            'prepared_by_user_id' => $preparedBy?->id,
            'reviewed_by_user_id' => null,
            'summary_json' => SitrepPayloadSchema::wrapSection($summary, $location),
            'situation_json' => SitrepPayloadSchema::wrapSection($situation, $location),
            'damage_json' => SitrepPayloadSchema::wrapSection($damage, $location),
            'population_json' => SitrepPayloadSchema::wrapSection($population, $location),
            'actions_json' => SitrepPayloadSchema::wrapSection($actions, $location),
            'needs_json' => SitrepPayloadSchema::wrapSection($needs, $location),
            'gaps_json' => SitrepPayloadSchema::wrapSection($gaps, $location),
            'source_snapshot_json' => SitrepPayloadSchema::wrapSection($sourceSnapshot, $location),
            'privacy_redactions_json' => $this->buildPrivacyRedactions(),
            'data_quality_json' => SitrepPayloadSchema::wrapSection($dataQuality, $location),
        ]);

        $this->relayOutbox->queue($report);

        return $report;
    }

    private function scopedIncidents(?User $preparedBy, Carbon $periodStart, Carbon $periodEnd): Collection
    {
        $query = Incident::query()
            ->with([
                'caller',
                'callSessions',
                'mediaItems',
                'messages.attachments',
                'incidentTypes.category',
                'incidentTypeDetails.incidentType',
                'incidentResourcesNeeded.resourceType.category',
                'teamAssignments.team.category',
                'citizenLocations',
            ]);

        if ($preparedBy?->role === UserRole::Operator) {
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
        $citizenLocations = $incidents->flatMap(fn (Incident $incident) => $incident->citizenLocations);
        $currentIncidents = $incidents
            ->filter(fn (Incident $incident) => $this->isCurrentIncident($incident))
            ->values();
        $resolvedIncidents = $incidents
            ->filter(fn (Incident $incident) => $this->statusValue($incident->status) === IncidentStatus::Resolved->value)
            ->values();
        $discardedIncidents = $incidents
            ->filter(fn (Incident $incident) => $this->statusValue($incident->status) === IncidentStatus::Discarded->value)
            ->values();

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

        $incidentStatuses = $incidents->mapWithKeys(fn (Incident $incident) => [
            $incident->id => $this->statusValue($incident->status),
        ]);

        $incidentById = $incidents->keyBy('id');

        $resourceRows = $resourceNeeds
            ->map(function ($need) use ($incidentStatuses, $incidentById): array {
                /** @var Incident|null $incident */
                $incident = $incidentById->get($need->incident_id);
                $resourceName = $need->resourceType?->name ?? 'Resource';
                $categoryName = $need->resourceType?->category?->name ?? 'Uncategorized';

                return [
                    'incident_id' => $need->incident_id,
                    'resource_type_id' => $need->resource_type_id,
                    'resource_type_name' => $resourceName,
                    'resource_type_category_id' => $need->resourceType?->category_id,
                    'resource_type_category_name' => $categoryName,
                    'name' => $resourceName,
                    'category_id' => $need->resourceType?->category_id,
                    'category' => $categoryName,
                    'unit' => $need->resourceType?->unit_label,
                    'unit_label' => $need->resourceType?->unit_label,
                    'quantity' => (int) $need->quantity_required,
                    'quantity_requested' => (int) $need->quantity_required,
                    'notes' => $need->notes,
                    'status' => (string) ($incidentStatuses[$need->incident_id] ?? ''),
                    'location_name' => $incident ? $this->areaLabel($incident) : null,
                    'source_hub_name' => $incident ? $this->areaLabel($incident) : null,
                ];
            })
            ->values();

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'incidents' => $incidents,
            'current_incidents' => $currentIncidents,
            'resolved_incidents' => $resolvedIncidents,
            'discarded_incidents' => $discardedIncidents,
            'status_counts' => $statusCounts,
            'call_sessions' => $callSessions,
            'team_assignments' => $teamAssignments,
            'current_team_assignments' => $teamAssignments
                ->filter(fn ($assignment) => $currentIncidents->contains('id', $assignment->incident_id))
                ->values(),
            'resource_needs' => $resourceRows,
            'current_resource_needs' => $resourceRows
                ->filter(fn (array $need) => in_array($need['status'], [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))
                ->values(),
            'field_details' => $fieldDetails,
            'current_field_details' => $fieldDetails
                ->filter(fn (IncidentTypeDetail $detail) => $currentIncidents->contains('id', $detail->incident_id))
                ->values(),
            'citizen_locations' => $citizenLocations,
            'type_rows' => $typeRows,
            'current_type_rows' => $typeRows
                ->filter(fn (array $row) => $currentIncidents->contains('id', $row['incident_id']))
                ->values(),
            'type_counts' => $typeRows->countBy('name')->sortDesc(),
            'current_type_counts' => $typeRows
            ->filter(fn (array $row) => $currentIncidents->contains('id', $row['incident_id']))
            ->countBy('name')
            ->sortDesc(),
            'resolved_type_counts' => $typeRows
                ->filter(fn (array $row) => $resolvedIncidents->contains('id', $row['incident_id']))
                ->countBy('name')
                ->sortDesc(),
            'location_counts' => $incidents
                ->countBy(fn (Incident $incident) => $this->areaLabel($incident))
                ->sortDesc(),
            'current_location_counts' => $currentIncidents
                ->countBy(fn (Incident $incident) => $this->areaLabel($incident))
                ->sortDesc(),
            'new_incident_count' => $incidents->filter(fn (Incident $incident) => $incident->created_at?->betweenIncluded($periodStart, $periodEnd))->count(),
            'carried_over_incident_count' => $incidents->filter(fn (Incident $incident) => $incident->created_at && $incident->created_at->lt($periodStart) && in_array($this->statusValue($incident->status), [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))->count(),
            'closed_incident_count' => $incidents
                ->filter(fn (Incident $incident) => $this->statusValue($incident->status) === IncidentStatus::Resolved->value)
                ->filter(fn (Incident $incident) => $incident->resolved_at?->betweenIncluded($periodStart, $periodEnd) || $incident->updated_at?->betweenIncluded($periodStart, $periodEnd))
                ->count(),
            'discarded_incident_count' => $discardedIncidents->count(),
            'active_at_close_incident_count' => $currentIncidents->count(),
        ];
    }

    private function buildSummary(array $context, Carbon $periodStart, Carbon $periodEnd, string $coverageArea): array
    {
        $incidents = $context['incidents'];
        $currentIncidents = $context['current_incidents'];
        $dominantType = $context['current_type_counts']->keys()->first() ?? 'Unclassified incidents';
        $hotspot = $this->currentHotspotLabel($context, $coverageArea);
        $activeCount = (int) ($context['status_counts'][IncidentStatus::Active->value] ?? 0);
        $deferredCount = (int) ($context['status_counts'][IncidentStatus::Deferred->value] ?? 0);
        $blockedAssignments = $context['current_team_assignments']
            ->filter(fn ($assignment) => ! in_array(strtolower((string) $assignment->status), ['completed', 'cancelled'], true))
            ->count();
        $criticalNeeds = $context['current_resource_needs']->sum('quantity');
        $lifeSafety = $this->lifeSafetySummary($context);
        $access = $this->accessSummary($context);
        $resolvedProgress = $this->resolvedProgressSummary($context);
        $missingLocations = $currentIncidents
            ->filter(fn (Incident $incident) => $incident->latitude === null || $incident->longitude === null)
            ->count();

        $posture = match (true) {
            $criticalNeeds > 0 || $blockedAssignments >= 3 => 'strained',
            ($activeCount + $deferredCount) > 0 => 'monitoring',
            default => 'stable',
        };

        $watchItems = collect([
            $lifeSafety['watch_item'],
            $access['watch_item'],
            $missingLocations > 0 ? sprintf('Citizen location unavailable for %d incident%s.', $missingLocations, $missingLocations === 1 ? '' : 's') : null,
            $blockedAssignments > 0 ? sprintf('%d assignment%s still open.', $blockedAssignments, $blockedAssignments === 1 ? '' : 's') : null,
            $criticalNeeds > 0 ? sprintf('%d requested resource unit%s still need review.', $criticalNeeds, $criticalNeeds === 1 ? '' : 's') : null,
        ])->filter()->values()->all();

        $gapCards = [
            [
                'label' => 'People at Risk',
                'value' => $lifeSafety['value'],
                'note' => $lifeSafety['note'],
            ],
            [
                'label' => 'Access to Help',
                'value' => $access['value'],
                'note' => $access['note'],
            ],
            [
                'label' => 'Response Progress',
                'value' => sprintf('%d open / %d addressed', $context['active_at_close_incident_count'], $context['closed_incident_count']),
                'note' => sprintf('%d in-progress assignment%s; %d resolved report%s treated as addressed history.', $blockedAssignments, $blockedAssignments === 1 ? '' : 's', $context['closed_incident_count'], $context['closed_incident_count'] === 1 ? '' : 's'),
            ],
        ];
        $accomplishmentCards = [
            [
                'label' => 'People Helped',
                'value' => $resolvedProgress['highlight_value'] ?? 'No resolved people count yet',
                'note' => $resolvedProgress['highlight_note'] ?? 'Resolved people and family counts will appear here when structured records are available.',
            ],
            [
                'label' => 'Handled Incidents',
                'value' => $resolvedProgress['value'] ?? sprintf('%d resolved report%s', $context['closed_incident_count'], $context['closed_incident_count'] === 1 ? '' : 's'),
                'note' => $resolvedProgress['handled_note'] ?? 'Resolved reports are treated as addressed history and excluded from current demand.',
            ],
            [
                'label' => 'Teams / Resources Deployed',
                'value' => $resolvedProgress['deployment_value'] ?? 'No completed deployment count yet',
                'note' => $resolvedProgress['deployment_note'] ?? 'Completed team and resolved-resource counts appear here when available.',
            ],
        ];

        return [
            'headline' => $this->buildHeadline($context, $coverageArea),
            'posture' => $posture,
            'posture_label' => ucfirst($posture),
            'posture_reason' => $this->postureReason($posture, $activeCount, $deferredCount, $blockedAssignments, $criticalNeeds),
            'gap_cards' => $gapCards,
            'accomplishment_cards' => $accomplishmentCards,
            'executive_cards' => array_merge($gapCards, $accomplishmentCards),
            'resolved_progress' => $resolvedProgress,
            'dominant_incident_type' => $dominantType,
            'hotspot_area' => $hotspot,
            'hotspot_note' => $this->hotspotNote($context),
            'primary_concern' => $watchItems[0] ?? 'No immediate operational blocker identified.',
            'priority_watch_items' => $watchItems,
            'key_change_since_previous' => 'No previous SITREP comparison is available in this first pass.',
            'supporting_metrics' => [
                'total_incidents' => $incidents->count(),
                'total_call_sessions' => $context['call_sessions']->count(),
                'multi_call_incidents' => $currentIncidents->filter(fn (Incident $incident) => $incident->callSessions->count() > 1)->count(),
                'incident_type_mentions' => $context['current_type_rows']->count(),
                'team_assignments' => $blockedAssignments,
                'resource_need_units' => $criticalNeeds,
                'new_this_period' => $context['new_incident_count'],
                'carried_over' => $context['carried_over_incident_count'],
                'closed_this_period' => $context['closed_incident_count'],
                'discarded_excluded' => $context['discarded_incident_count'],
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
        $total = $context['current_incidents']->count();
        $dominantType = $context['current_type_counts']->keys()->first() ?? 'unclassified incidents';
        $hotspot = $this->currentHotspotLabel($context, 'multiple areas');

        return [
            'narrative' => $total > 0
                ? sprintf('%d active/deferred incident%s %s in the current operating picture. The leading current classification is %s, with current concerns associated with %s.', $total, $total === 1 ? '' : 's', $total === 1 ? 'remains' : 'remain', $dominantType, $hotspot)
                : 'No active/deferred incidents remain in the current operating picture.',
            'executive_assessment' => $this->executiveAssessment($context),
            'decision_points' => $this->decisionPoints($context),
            'current_operating_picture' => $this->currentOperatingPicture($context),
            'areas_of_concern' => $this->areasOfConcern($context),
            'concern_groups' => $this->concernGroups($context),
            'period_activity' => $this->periodActivity($context),
            'verification_notes' => $this->verificationNotes($context),
            'locations' => $this->countMapRows($context['current_location_counts'], 'area'),
            'period_locations' => $this->countMapRows($context['location_counts'], 'area'),
            'incident_types' => $this->countMapRows($context['current_type_counts'], 'type'),
            'period_incident_types' => $this->countMapRows($context['type_counts'], 'type'),
            'multi_type_incident_count' => $context['current_incidents']->filter(fn (Incident $incident) => $incident->incidentTypes->count() > 1)->count(),
            'notable_events' => $this->notableEvents($context),
            'confidence_note' => 'Incident type buckets are type mentions; totals can exceed total incidents when an incident has multiple types.',
        ];
    }

    private function buildDamage(array $context): array
    {
        $items = $this->detailsForSection($context['current_field_details'], 'damage');
        $historicalItems = $this->detailsForSection($context['field_details'], 'damage');

        return [
            'items' => $items,
            'damage_groups' => $this->damageGroups($items),
            'confirmed_count' => 0,
            'reported_count' => count($items),
            'unverified_count' => count($items),
            'empty_state' => count($items) === 0 ? 'No damage fields have been reported for this period.' : null,
            'confidence_note' => count($items) > 0 ? 'Current damage entries are reported from active/deferred incident type fields and should be verified before external release.' : 'No configured/reported current damage values were found.',
            'historical_items' => $historicalItems,
        ];
    }

    private function buildPopulation(array $context): array
    {
        $items = $this->detailsForSection($context['current_field_details'], 'population');
        $numericTotal = collect($items)
            ->sum(fn (array $item) => is_numeric($item['value']) ? (float) $item['value'] : 0);

        $assisted = $context['current_incidents']->pluck('citizen_id')->filter()->unique()->count();

        return [
            'citizens_assisted' => $assisted,
            'items' => $items,
            'population_groups' => $this->populationGroups($items),
            'record_count' => count($items),
            'numeric_total' => $numericTotal,
            'numeric_total_note' => 'Numeric population fields may overlap across categories and should not be treated as a consolidated total unless verified.',
            'empty_state' => count($items) === 0 ? 'No population fields have been reported for this period.' : null,
            'confidence_note' => count($items) > 0 ? 'Current population and life-safety values are sourced from active/deferred incident type fields. Category counts may overlap.' : 'Current population details are not reported or not configured.',
            'historical_items' => $this->detailsForSection($context['field_details'], 'population'),
        ];
    }

    private function damageGroups(array $items): array
    {
        return collect($items)
            ->groupBy(fn (array $item): string => (string) ($item['label'] ?? 'Reported damage'))
            ->map(function (Collection $group, string $label): array {
                $values = $group
                    ->pluck('value')
                    ->filter()
                    ->unique()
                    ->take(3)
                    ->implode('; ');

                return [
                    'damage_type' => $label,
                    'reports' => $group->pluck('incident_id')->unique()->count(),
                    'severity_signal' => $this->damageSeveritySignal($group, $values),
                    'affected_assets' => $this->damageAssetLabel($label, $group),
                    'source_incidents' => $group->pluck('incident_id')->unique()->values()->all(),
                ];
            })
            ->sortByDesc('reports')
            ->values()
            ->all();
    }

    private function damageSeveritySignal(Collection $group, string $fallback): string
    {
        $levels = $group
            ->pluck('source.damage_level')
            ->filter()
            ->map(fn (string $level): string => str_replace('_', ' ', strtolower($level)))
            ->unique()
            ->sortBy(fn (string $level): int => match ($level) {
                'minor' => 10,
                'minor to moderate' => 20,
                'moderate' => 30,
                'moderate to severe' => 40,
                'high' => 50,
                'severe' => 60,
                default => 100,
            })
            ->values();

        $habitabilityReview = $group
            ->filter(fn (array $item): bool => array_key_exists('habitable', data_get($item, 'source', []) ?? []))
            ->count();

        if ($levels->isNotEmpty()) {
            return $this->joinParts([
                $levels->implode(', ').' reported severity',
                $habitabilityReview > 0 ? sprintf('%d habitability signal%s', $habitabilityReview, $habitabilityReview === 1 ? '' : 's') : null,
            ]);
        }

        return $fallback !== '' ? $fallback : 'Reported; verification required.';
    }

    private function damageAssetLabel(string $label, Collection $group): string
    {
        $normalized = strtolower($label);
        $assets = $group
            ->pluck('source.asset_type')
            ->filter()
            ->unique()
            ->values();

        if ($assets->isNotEmpty()) {
            return $assets->take(3)->implode(', ');
        }

        if (str_contains($normalized, 'shelter')) {
            return 'Residential structures';
        }

        if (str_contains($normalized, 'vehicle')) {
            return 'Vehicles';
        }

        if (str_contains($normalized, 'infrastructure')) {
            return 'Infrastructure / access assets';
        }

        return 'Reported assets';
    }

    private function populationGroups(array $items): array
    {
        return collect($items)
            ->groupBy(fn (array $item): string => (string) ($item['label'] ?? 'Population signal'))
            ->map(function (Collection $group, string $label): array {
                return [
                    'population_signal' => $label,
                    'reports' => $group->pluck('incident_id')->unique()->count(),
                    'people_families' => $this->populationPeopleFamilies($group),
                    'notes' => $this->populationNotes($group),
                    'breakdowns' => $this->populationBreakdowns($group),
                    'source_incidents' => $group->pluck('incident_id')->unique()->values()->all(),
                ];
            })
            ->sortByDesc('reports')
            ->values()
            ->all();
    }

    private function populationPeopleFamilies(Collection $group): string
    {
        $people = $group->sum(fn (array $item): int => (int) data_get($item, 'source.member_count', 0));
        $families = $group->sum(fn (array $item): int => (int) data_get($item, 'source.families', 0));

        if ($people > 0 || $families > 0) {
            return $this->joinParts([
                $families > 0 ? sprintf('%d %s', $families, $families === 1 ? 'family' : 'families') : null,
                $people > 0 ? sprintf('%d people', $people) : null,
            ]);
        }

        return sprintf('%d record%s', $group->count(), $group->count() === 1 ? '' : 's');
    }

    private function populationNotes(Collection $group): string
    {
        $displaced = $group->filter(fn (array $item): bool => $this->yesNo(data_get($item, 'source.displaced', false)) === 'Yes')->count();
        $conditions = $group
            ->pluck('source.condition')
            ->filter()
            ->unique()
            ->take(2)
            ->implode('; ');

        return $this->joinParts([
            $displaced > 0 ? sprintf('%d displacement signal%s', $displaced, $displaced === 1 ? '' : 's') : null,
            $conditions !== '' ? $conditions : null,
        ]);
    }

    private function populationBreakdowns(Collection $group): array
    {
        return collect([
            [
                'breakdown' => 'Children',
                'count' => $group->sum(fn (array $item): int => $this->sumSourceKeys($item, ['children_count', 'children'])),
            ],
            [
                'breakdown' => 'Senior citizens',
                'count' => $group->sum(fn (array $item): int => $this->sumSourceKeys($item, ['senior_count', 'senior_citizens', 'seniors', 'elderly_count', 'adult_senior_count'])),
            ],
            [
                'breakdown' => 'PWD',
                'count' => $group->sum(fn (array $item): int => $this->sumSourceKeys($item, ['pwd_count', 'persons_with_disability', 'persons_with_disabilities', 'adult_pwd_count', 'children_pwd_count'])),
            ],
            [
                'breakdown' => 'Pregnant',
                'count' => $group->sum(fn (array $item): int => $this->sumSourceKeys($item, ['pregnant_count', 'pregnant_women', 'adult_pregnant_count'])),
            ],
        ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }

    private function sumSourceKeys(array $item, array $keys): int
    {
        return collect($keys)->sum(fn (string $key): int => (int) data_get($item, 'source.'.$key, 0));
    }

    private function buildActions(array $context, Carbon $generatedAt): array
    {
        $assignmentRows = $context['current_team_assignments']
            ->map(fn ($assignment) => [
                'incident_id' => $assignment->incident_id,
                'team' => $assignment->team?->name ?? 'Team',
                'category' => $assignment->team?->category?->name ?? 'Uncategorized',
                'status' => (string) $assignment->status,
                'status_label' => $this->statusLabel($assignment->status),
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'accepted_at' => $assignment->accepted_at?->toIso8601String(),
                'enroute_at' => $assignment->enroute_at?->toIso8601String(),
                'arrived_at' => $assignment->arrived_at?->toIso8601String(),
                'completed_at' => $assignment->completed_at?->toIso8601String(),
                'cancelled_at' => $assignment->cancelled_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'assignment_status_counts' => $context['current_team_assignments']->countBy(fn ($assignment) => (string) $assignment->status)->all(),
            'deployment_groups' => $this->teamDeploymentGroups($context['current_team_assignments']),
            'timing_rows' => $this->teamAssignmentTimingRows($context['current_team_assignments'], $generatedAt),
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

    private function teamDeploymentGroups(Collection $assignments): array
    {
        return $assignments
            ->groupBy(fn ($assignment): string => ($assignment->team?->category?->name ?? 'Uncategorized').'|'.($assignment->team?->name ?? 'Team'))
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'category' => $first?->team?->category?->name ?? 'Uncategorized',
                    'team' => $first?->team?->name ?? 'Team',
                    'status_counts' => $this->assignmentStatusBuckets($group),
                    'total_assignments' => $group->count(),
                    'reports_covered' => $group->pluck('incident_id')->unique()->count(),
                    'incident_ids' => $group->pluck('incident_id')->unique()->values()->all(),
                ];
            })
            ->sortBy([
                ['category', 'asc'],
                ['team', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function assignmentStatusBuckets(Collection $assignments): array
    {
        $buckets = [
            'requested' => 0,
            'assigned' => 0,
            'accepted' => 0,
            'en_route' => 0,
            'on_scene' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'other' => 0,
        ];

        foreach ($assignments as $assignment) {
            $status = strtolower(str_replace(['-', ' '], '_', trim((string) $assignment->status)));

            if (array_key_exists($status, $buckets)) {
                $buckets[$status]++;

                continue;
            }

            $buckets['other']++;
        }

        return $buckets;
    }

    private function teamAssignmentTimingRows(Collection $assignments, Carbon $generatedAt): array
    {
        return $assignments
            ->map(fn ($assignment) => [
                'incident_id' => $assignment->incident_id,
                'team' => $assignment->team?->name ?? 'Team',
                'current_status' => $this->statusLabel($assignment->status),
                'assigned_to_accepted' => $this->durationBetween($assignment->assigned_at, $assignment->accepted_at),
                'accepted_to_en_route' => $this->durationBetween($assignment->accepted_at, $assignment->enroute_at),
                'en_route_to_on_scene' => $this->durationBetween($assignment->enroute_at, $assignment->arrived_at),
                'on_scene_to_completed' => $this->durationBetween($assignment->arrived_at, $assignment->completed_at),
                'assigned_to_cancelled' => $this->durationBetween($assignment->assigned_at, $assignment->cancelled_at),
                'elapsed_time' => $this->assignmentElapsedTime($assignment, $generatedAt),
            ])
            ->values()
            ->all();
    }

    private function assignmentElapsedTime($assignment, Carbon $generatedAt): string
    {
        if (in_array($this->normalizedAssignmentStatus($assignment->status), ['completed', 'cancelled'], true)) {
            return '';
        }

        return $this->durationSince($this->assignmentStatusStartedAt($assignment), $generatedAt);
    }

    private function assignmentStatusStartedAt($assignment): ?Carbon
    {
        $startedAt = match ($this->normalizedAssignmentStatus($assignment->status)) {
            'accepted' => $assignment->accepted_at,
            'en_route' => $assignment->enroute_at,
            'on_scene' => $assignment->arrived_at,
            'completed' => $assignment->completed_at,
            'cancelled' => $assignment->cancelled_at,
            default => $assignment->assigned_at,
        };

        return $startedAt ?? $assignment->assigned_at;
    }

    private function normalizedAssignmentStatus(mixed $status): string
    {
        return strtolower(str_replace(['-', ' '], '_', trim((string) $status)));
    }

    private function durationBetween(?Carbon $start, ?Carbon $end): string
    {
        if (! $start || ! $end || $end->lt($start)) {
            return '';
        }

        return $this->formatDurationSeconds((int) floor($start->diffInSeconds($end)));
    }

    private function durationSince(?Carbon $start, Carbon $asOf): string
    {
        if (! $start) {
            return '';
        }

        if ($asOf->lt($start)) {
            return '';
        }

        return $this->formatDurationSeconds((int) floor($start->diffInSeconds($asOf)));
    }

    private function formatDurationSeconds(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        if ($minutes < 1) {
            return '<1m';
        }

        $days = intdiv($minutes, 1440);
        $minutes %= 1440;
        $hours = intdiv($minutes, 60);
        $minutes %= 60;

        return collect([
            $days > 0 ? $days.'d' : null,
            $hours > 0 ? $hours.'h' : null,
            $minutes > 0 ? $minutes.'m' : null,
        ])->filter()->take(2)->implode(' ');
    }

    private function buildNeeds(array $context): array
    {
        $rows = $context['current_resource_needs']
            ->groupBy(fn (array $item) => ($item['resource_type_id'] ?? 0).'|'.($item['category'] ?? 'Uncategorized').'|'.$item['name'])
            ->map(fn (Collection $items) => [
                'category' => $items->first()['category'] ?? 'Uncategorized',
                'resource_type_category_id' => $items->first()['resource_type_category_id'] ?? null,
                'resource_type_category_name' => $items->first()['resource_type_category_name'] ?? ($items->first()['category'] ?? 'Uncategorized'),
                'resource' => $items->first()['name'] ?? 'Resource',
                'resource_type_id' => $items->first()['resource_type_id'] ?? null,
                'resource_type_name' => $items->first()['resource_type_name'] ?? ($items->first()['name'] ?? 'Resource'),
                'quantity_requested' => $items->sum('quantity'),
                'incident_count' => $items->pluck('incident_id')->unique()->count(),
                'incident_ids' => $items->pluck('incident_id')->unique()->values()->all(),
                'unit' => $items->first()['unit'] ?? null,
                'unit_label' => $items->first()['unit_label'] ?? ($items->first()['unit'] ?? null),
                'status' => $items->contains(fn (array $item) => in_array($item['status'], [IncidentStatus::Active->value, IncidentStatus::Deferred->value], true))
                    ? 'open'
                    : 'closed',
                'sources' => $items
                    ->groupBy(fn (array $item) => (string) ($item['location_name'] ?? $item['source_hub_name'] ?? 'Current Location'))
                    ->map(fn (Collection $sourceItems, string $location): array => [
                        'source_hub_name' => $location,
                        'location_name' => $location,
                        'quantity_requested' => $sourceItems->sum('quantity'),
                        'incident_ids' => $sourceItems->pluck('incident_id')->unique()->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'items' => $rows,
            'total_quantity_requested' => collect($rows)->sum('quantity_requested'),
            'category_groups' => $this->resourceCategoryGroups($rows),
            'empty_state' => count($rows) === 0 ? 'No structured resource needs were requested in this period.' : null,
            'confidence_note' => 'Current needs are derived from structured resource requests on active/deferred incidents. They represent requested needs, not confirmed supply, dispatch, delivery, or consumption.',
        ];
    }

    private function buildGaps(array $context): array
    {
        $items = [];

        $missingLocations = $context['current_incidents']
            ->filter(fn (Incident $incident) => $incident->latitude === null || $incident->longitude === null)
            ->count();

        if ($missingLocations > 0) {
            $items[] = [
                'type' => 'missing_location',
                'category' => 'Data confidence',
                'title' => 'Citizen location unavailable',
                'decision_relevance' => 'Location uncertainty can delay dispatch validation, area prioritization, and public-facing geographic statements.',
                'evidence' => sprintf('%d active/deferred incident%s do not have citizen coordinates.', $missingLocations, $missingLocations === 1 ? '' : 's'),
                'confidence_note' => 'Area names may still be present, but exact coordinates are unavailable in the current report data.',
                'public_visible' => true,
            ];
        }

        $openNeeds = $context['current_resource_needs']->sum('quantity');

        if ($openNeeds > 0) {
            $resourceCategories = $this->resourceCategoryGroups($context['current_resource_needs']->all());

            $items[] = [
                'type' => 'open_needs',
                'category' => 'Operational constraint',
                'title' => 'Resource supply not confirmed',
                'decision_relevance' => 'Leadership can see current demand, but should not treat the requested units as confirmed supply or completed delivery.',
                'evidence' => sprintf('%d requested resource unit%s remain tied to active/deferred incidents. Category detail is shown in Current Resource Posture.', $openNeeds, $openNeeds === 1 ? '' : 's'),
                'confidence_note' => 'Hotline records the request; dispatch, arrival, delivery, consumption, and sufficiency are not confirmed by this SITREP.',
                'public_visible' => true,
                'resource_categories' => $resourceCategories,
                'resource_needs' => $this->resourceGapEvidenceRows($context),
            ];
        }

        $roadConstraints = $this->roadAccessRows($context['current_field_details'])
            ->filter(fn (array $row) => ! in_array(strtolower($row['status']), ['', 'open', 'clear', 'cleared', 'passable'], true))
            ->values();

        if ($roadConstraints->isNotEmpty()) {
            $statusCounts = $roadConstraints
                ->countBy(fn (array $row) => $row['status'] !== '' ? $row['status'] : 'Unspecified')
                ->map(fn (int $count, string $status) => sprintf('%d %s', $count, strtolower($status)))
                ->values()
                ->implode(', ');

            $items[] = [
                'type' => 'road_access',
                'category' => 'Operational constraint',
                'title' => 'Road/access constraints may affect field movement',
                'decision_relevance' => 'Route constraints can affect response timing, staging decisions, and whether public routing advisories should be issued.',
                'evidence' => sprintf('%d current route constraint%s reported: %s.', $roadConstraints->count(), $roadConstraints->count() === 1 ? '' : 's', $statusCounts),
                'confidence_note' => 'Road/access conditions are reported by incident records and should be verified before public routing guidance or major deployment assumptions.',
                'public_visible' => true,
                'items' => $roadConstraints->all(),
            ];
        }

        $populationItems = $this->detailsForSection($context['current_field_details'], 'population');

        if (count($populationItems) > 0) {
            $labels = collect($populationItems)
                ->pluck('label')
                ->unique()
                ->values()
                ->implode(', ');

            $items[] = [
                'type' => 'population_verification',
                'category' => 'Data confidence',
                'title' => 'Population figures require verification',
                'decision_relevance' => 'Population fields should guide life-safety awareness, but should not be used as one consolidated affected-person total without validation.',
                'evidence' => sprintf('%d current population/life-safety record%s reported: %s.', count($populationItems), count($populationItems) === 1 ? '' : 's', $labels !== '' ? $labels : 'configured population fields'),
                'confidence_note' => 'Family, shelter, evacuation, patient, missing-person, and affected-person fields may overlap.',
                'public_visible' => true,
            ];
        }

        return [
            'items' => $items,
            'title' => 'Response Constraints and Confidence Gaps',
            'intro' => 'This section separates operational constraints from information limits so leaders can judge what might block a clean decision.',
            'empty_state' => count($items) === 0 ? 'No response constraints or confidence gaps were identified from configured current data.' : null,
        ];
    }

    private function buildDataQuality(array $context): array
    {
        $missingLocations = $context['current_incidents']
            ->filter(fn (Incident $incident) => $incident->latitude === null || $incident->longitude === null)
            ->count();
        $withoutTypes = $context['current_incidents']
            ->filter(fn (Incident $incident) => $incident->incidentTypes->isEmpty())
            ->count();
        $withoutAssignments = $context['current_incidents']
            ->filter(fn (Incident $incident) => $incident->teamAssignments->isEmpty())
            ->count();

        return [
            'global_note' => 'Generated from Hotline incident data using current-picture rules: active/deferred reports drive current posture, resolved reports are addressed history, and discarded reports are excluded.',
            'counting_notes' => $this->countingNotes($context),
            'missing_citizen_location_count' => $missingLocations,
            'incidents_without_type_count' => $withoutTypes,
            'incidents_without_assignment_count' => $withoutAssignments,
            'unmapped_field_count' => $context['current_field_details']
                ->filter(fn (IncidentTypeDetail $detail) => $this->classifyDetail($detail) === 'situation')
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function countingNotes(array $context): array
    {
        $resolvedCount = (int) $context['closed_incident_count'];
        $discardedCount = (int) $context['discarded_incident_count'];

        if (($resolvedCount + $discardedCount) === 0) {
            return [];
        }

        return [[
            'type' => 'counting_scope',
            'category' => 'Counting note',
            'title' => 'Resolved and discarded reports were excluded from current pressure',
            'body' => 'This keeps the operating picture focused on reports that still need leadership visibility or downstream coordination.',
            'evidence' => sprintf('%d resolved report%s treated as addressed history; %d discarded report%s excluded from posture, demand, and severity.', $resolvedCount, $resolvedCount === 1 ? ' was' : 's were', $discardedCount, $discardedCount === 1 ? ' was' : 's were'),
            'confidence_note' => 'A resolved report cannot carry pending entries under current Hotline rules; discarded reports are neglected for current posture.',
        ]];
    }

    private function buildSourceSnapshot(array $context, Carbon $periodStart, Carbon $periodEnd, array $hubNodeSnapshot, bool $systemGenerated, ?User $preparedBy): array
    {
        return [
            'period_started_at' => $periodStart->toIso8601String(),
            'period_ended_at' => $periodEnd->toIso8601String(),
            'generation' => [
                'type' => $systemGenerated ? 'system' : 'manual',
                'prepared_by_label' => $this->preparedByLabel($systemGenerated, $preparedBy),
            ],
            'incident_ids' => $context['incidents']->pluck('id')->values()->all(),
            'incident_coordinates' => $this->incidentCoordinates($context['incidents']),
            'media_refs' => $this->mediaReferences($context['current_incidents'], $hubNodeSnapshot),
            'call_session_ids' => $context['call_sessions']->pluck('id')->values()->all(),
            'team_assignment_ids' => $context['team_assignments']->pluck('id')->values()->all(),
            'resource_need_ids' => $context['incidents']->flatMap(fn (Incident $incident) => $incident->incidentResourcesNeeded)->pluck('id')->values()->all(),
            'incident_type_detail_ids' => $context['field_details']->pluck('id')->values()->all(),
            'hotline' => $this->buildHotlineSnapshot(),
            'hub_node' => $hubNodeSnapshot,
            'hub_nodes' => [],
            'adapter_version' => 1,
            'counting_rule_version' => 2,
        ];
    }

    private function incidentCoordinates(Collection $incidents): array
    {
        return $incidents
            ->filter(fn (Incident $incident): bool => $incident->latitude !== null && $incident->longitude !== null)
            ->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'lat' => round((float) $incident->latitude, 5),
                'lng' => round((float) $incident->longitude, 5),
            ])
            ->values()
            ->all();
    }

    private function mediaReferences(Collection $incidents, array $hubNodeSnapshot): array
    {
        $sourceHubId = $this->sourceHubIdFromSnapshot($hubNodeSnapshot);
        $refs = [];

        foreach ($incidents as $incident) {
            foreach ($incident->mediaItems as $media) {
                $metadata = is_array($media->metadata_json) ? $media->metadata_json : [];

                $refs[] = [
                    'kind' => 'incident_media',
                    'source_hub_id' => $sourceHubId,
                    'incident_id' => (int) $incident->id,
                    'incident_ref' => $this->incidentReference($incident),
                    'media_id' => (int) $media->id,
                    'type' => (string) $media->type,
                    'mime_type' => $this->nullableText($metadata['mime_type'] ?? null),
                    'original_filename' => $this->safeOriginalFilename($metadata['original_filename'] ?? $metadata['filename'] ?? null),
                    'created_at' => $media->created_at?->toIso8601String(),
                    'peer_role' => $this->nullableText($media->peer_role),
                    'available_at' => $media->available_at?->toIso8601String(),
                ];
            }

            foreach ($incident->messages as $message) {
                foreach ($message->attachments as $attachment) {
                    $refs[] = [
                        'kind' => 'message_attachment',
                        'source_hub_id' => $sourceHubId,
                        'incident_id' => (int) $incident->id,
                        'incident_ref' => $this->incidentReference($incident),
                        'attachment_id' => (int) $attachment->id,
                        'message_id' => (int) $message->id,
                        'type' => (string) $attachment->type,
                        'mime_type' => (string) $attachment->mime_type,
                        'original_filename' => $this->safeOriginalFilename($attachment->original_filename),
                        'created_at' => $attachment->created_at?->toIso8601String(),
                        'uploader_role' => $this->nullableText($message->sender_role),
                    ];
                }
            }
        }

        usort($refs, static fn (array $a, array $b): int => [
            $a['incident_id'] ?? 0,
            $a['kind'] ?? '',
            $a['media_id'] ?? $a['attachment_id'] ?? 0,
        ] <=> [
            $b['incident_id'] ?? 0,
            $b['kind'] ?? '',
            $b['media_id'] ?? $b['attachment_id'] ?? 0,
        ]);

        return $refs;
    }

    private function sourceHubIdFromSnapshot(array $hubNodeSnapshot): ?string
    {
        $snapshot = is_array($hubNodeSnapshot['snapshot'] ?? null) ? $hubNodeSnapshot['snapshot'] : [];
        $hubId = trim((string) ($snapshot['hub_id'] ?? $snapshot['id'] ?? $snapshot['relay_hub_id'] ?? ''));

        return $hubId !== '' ? $hubId : null;
    }

    private function incidentReference(Incident $incident): string
    {
        return sprintf('#%06d', (int) $incident->id);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function safeOriginalFilename(mixed $value): ?string
    {
        $filename = trim((string) $value);

        if ($filename === '' || preg_match('/[\/\\\\\x00-\x1F\x7F]/', $filename) === 1) {
            return null;
        }

        return substr($filename, 0, 180);
    }

    private function preparedByLabel(bool $systemGenerated, ?User $preparedBy): string
    {
        if ($systemGenerated || $preparedBy === null) {
            return 'System Generated';
        }

        $name = trim((string) $preparedBy->name);

        return $name !== '' ? $name : 'System Generated';
    }

    private function coverageAreaFromHubNode(array $hubNodeSnapshot): string
    {
        $snapshot = ($hubNodeSnapshot['available'] ?? false) ? ($hubNodeSnapshot['snapshot'] ?? []) : [];
        $name = trim((string) ($snapshot['name'] ?? ''));

        return $name !== '' ? $name : 'PBB Hotline Coverage Area';
    }

    private function buildHubNodeSnapshot(): array
    {
        $url = trim((string) config('services.relay.hub_json_url', ''));

        if ($url === '') {
            return [
                'available' => false,
                'source' => 'relay_hub_json',
                'error' => 'Relay hub JSON URL is not configured.',
            ];
        }

        try {
            $response = Http::timeout((int) config('services.relay.hub_json_timeout', 5))
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                return $this->fallbackHubNodeSnapshot(
                    $url,
                    sprintf('Relay hub JSON request was not successful. HTTP status: %d.', $response->status()),
                );
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return $this->fallbackHubNodeSnapshot($url, 'Relay hub JSON response was not an object.');
            }

            Cache::put(self::HUB_NODE_CACHE_KEY, $payload, now()->addDays(7));

            return [
                'available' => true,
                'source' => 'relay_hub_json',
                'url' => $url,
                'snapshot' => $payload,
            ];
        } catch (\Throwable $exception) {
            return $this->fallbackHubNodeSnapshot($url, $exception->getMessage());
        }
    }

    private function fallbackHubNodeSnapshot(string $url, string $error): array
    {
        $cached = Cache::get(self::HUB_NODE_CACHE_KEY);

        if (is_array($cached)) {
            return [
                'available' => true,
                'source' => 'relay_hub_json',
                'url' => $url,
                'snapshot' => $cached,
                'stale' => true,
                'last_error' => $error,
            ];
        }

        return [
            'available' => false,
            'source' => 'relay_hub_json',
            'url' => $url,
            'error' => $error,
        ];
    }

    private function buildHotlineSnapshot(): array
    {
        $release = $this->releaseManifest();
        $build = is_array($release['build'] ?? null) ? $release['build'] : [];

        return [
            'app' => $release['app'] ?? 'pbb-hotline',
            'name' => $release['name'] ?? config('app.name'),
            'version' => config('app.version'),
            'display_version' => $release['display_version'] ?? config('app.version'),
            'release_name' => config('app.release_name'),
            'release_date' => config('app.release_date'),
            'build' => [
                'id' => $build['id'] ?? null,
                'git_commit' => $build['git_commit'] ?? null,
                'built_at' => $build['built_at'] ?? null,
            ],
        ];
    }

    private function releaseManifest(): array
    {
        $path = base_path('release.json');

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
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
            ->flatMap(fn (IncidentTypeDetail $detail) => $this->detailRows($detail, $section))
            ->values()
            ->all();
    }

    private function detailRows(IncidentTypeDetail $detail, string $section): array
    {
        $decoded = json_decode((string) $detail->field_value, true);
        $decodedArray = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
        $rows = $this->decodedDetailRows($detail);

        if ($rows === []) {
            if ($decodedArray) {
                return [];
            }

            return [$this->detailRow($detail, $detail->field_label, $this->scalarDetailValue($detail))];
        }

        return collect($rows)
            ->map(fn (array $row) => $this->detailRow(
                $detail,
                $this->detailRowLabel($detail, $row, $section),
                $this->detailRowValue($detail, $row, $section),
                null,
                $row
            ))
            ->all();
    }

    private function decodedDetailRows(IncidentTypeDetail $detail): array
    {
        $decoded = json_decode((string) $detail->field_value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $rows = array_is_list($decoded) ? $decoded : [$decoded];

        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->values()
            ->all();
    }

    private function detailRow(IncidentTypeDetail $detail, string $label, string $value, ?string $unit = null, ?array $source = null): array
    {
        return [
            'incident_id' => $detail->incident_id,
            'incident_type_id' => $detail->incident_type_id,
            'label' => $label,
            'key' => $detail->field_key,
            'value' => $value,
            'unit' => $unit ?? $detail->unit,
            'confidence' => 'reported',
            'source' => $source,
        ];
    }

    private function scalarDetailValue(IncidentTypeDetail $detail): string
    {
        $value = trim((string) $detail->field_value);

        if ($detail->unit && $value !== '') {
            return $value;
        }

        return $value;
    }

    private function detailRowLabel(IncidentTypeDetail $detail, array $row, string $section): string
    {
        $label = $this->detailSearchText($detail);
        $preset = $this->detailPreset($detail);

        if ($section === 'damage') {
            if ($preset === 'shelterDamage' || str_contains($label, 'shelter')) {
                return 'Shelter damage';
            }

            if ($preset === 'vehicleInvolved' || str_contains($label, 'vehicle')) {
                return 'Vehicle involved';
            }

            if ($preset === 'infrastructureDamage' || str_contains($label, 'infrastructure')) {
                return 'Infrastructure damage';
            }
        }

        if ($section === 'population') {
            if ($preset === 'person' || str_contains($label, 'missing')) {
                return 'Missing person';
            }

            if ($preset === 'casualtyPatient' || str_contains($label, 'patient') || str_contains($label, 'injur')) {
                return 'Patient or injured person';
            }

            if ($preset === 'family' || str_contains($label, 'famil')) {
                return 'Affected family';
            }
        }

        if ($section === 'gaps' && ($preset === 'roadAccessStatus' || str_contains($label, 'road') || str_contains($label, 'access'))) {
            return 'Road or access status';
        }

        return $detail->field_label ?: 'Reported fact';
    }

    private function detailRowValue(IncidentTypeDetail $detail, array $row, string $section): string
    {
        $label = $this->detailSearchText($detail);
        $preset = $this->detailPreset($detail);

        if ($section === 'damage') {
            if ($preset === 'shelterDamage' || str_contains($label, 'shelter')) {
                return $this->joinParts([
                    $this->severityPhrase($row, ['damage_level', 'damage_severity', 'severity'], 'damage'),
                    $row['structure_type'] ?? null,
                    $this->countPhrase($row, ['damaged_structures'], 'damaged structure', 'damaged structures'),
                    $this->countPhrase($row, ['destroyed_structures'], 'destroyed structure', 'destroyed structures'),
                    $this->countPhrase($row, ['affected_families', 'families_affected'], 'family affected', 'families affected'),
                    $this->countPhrase($row, ['affected_persons', 'persons_affected'], 'persons affected'),
                    array_key_exists('habitable', $row) ? 'habitable: '.$this->yesNo($row['habitable']) : null,
                    $row['notes'] ?? null,
                ]);
            }

            if ($preset === 'vehicleInvolved' || str_contains($label, 'vehicle')) {
                return $this->joinParts([
                    $row['vehicle_type'] ?? null,
                    isset($row['plate_number']) ? 'plate: '.$row['plate_number'] : null,
                    $this->severityPhrase($row, ['damage_level', 'damage'], 'damage'),
                    $row['description'] ?? null,
                    array_key_exists('drivable', $row) ? 'drivable: '.$this->yesNo($row['drivable']) : null,
                ]);
            }

            if ($preset === 'infrastructureDamage' || str_contains($label, 'infrastructure')) {
                return $this->joinParts([
                    $this->severityPhrase($row, ['damage_level', 'severity'], 'damage'),
                    $row['asset_type'] ?? null,
                    $row['damage'] ?? null,
                    isset($row['operational_status']) ? 'operational status: '.$row['operational_status'] : null,
                    isset($row['estimated_affected_users']) ? sprintf('%s estimated affected users', $row['estimated_affected_users']) : null,
                    isset($row['public_safety_risk']) ? 'public safety risk: '.$row['public_safety_risk'] : null,
                    $row['notes'] ?? null,
                ]);
            }
        }

        if ($section === 'population') {
            if ($preset === 'person' || str_contains($label, 'missing')) {
                return $this->joinParts([
                    $this->personPhrase($row),
                    $row['name'] ?? null,
                    $row['condition'] ?? null,
                    isset($row['last_seen_location']) ? 'last seen at '.$row['last_seen_location'] : null,
                    $row['description'] ?? null,
                ]);
            }

            if ($preset === 'casualtyPatient' || str_contains($label, 'patient') || str_contains($label, 'injur')) {
                return $this->joinParts([
                    $row['name'] ?? null,
                    isset($row['age']) ? 'age '.$row['age'] : null,
                    isset($row['condition']) ? $row['condition'].' condition' : null,
                    isset($row['triage_category']) || isset($row['triage']) ? 'triage: '.($row['triage_category'] ?? $row['triage']) : null,
                    array_key_exists('transported', $row) ? 'transported: '.$this->yesNo($row['transported']) : null,
                ]);
            }

            if ($preset === 'family' || str_contains($label, 'famil')) {
                return $this->joinParts([
                    $this->countPhrase($row, ['families'], 'family', 'families'),
                    $this->countPhrase($row, ['individuals', 'people', 'persons', 'member_count'], 'person', 'persons'),
                    $this->countPhrase($row, ['children', 'children_count'], 'child', 'children'),
                    $this->vulnerableCount($row) > 0 ? sprintf('%s vulnerable', $this->vulnerableCount($row)) : null,
                    array_key_exists('displaced', $row) || array_key_exists('temporary_shelter_needed', $row) ? 'displaced: '.$this->yesNo($row['displaced'] ?? $row['temporary_shelter_needed']) : null,
                    array_key_exists('returned_home', $row) ? 'returned home: '.$this->yesNo($row['returned_home']) : null,
                    $row['notes'] ?? null,
                ]);
            }
        }

        if ($section === 'gaps' && ($preset === 'roadAccessStatus' || str_contains($label, 'road') || str_contains($label, 'access'))) {
            return $this->joinParts([
                $row['route_location'] ?? $row['location'] ?? $row['description'] ?? null,
                isset($row['status']) ? 'status: '.$row['status'] : null,
                isset($row['obstruction_type']) ? 'obstruction: '.$row['obstruction_type'] : null,
                array_key_exists('cleared', $row) ? 'cleared: '.$this->yesNo($row['cleared']) : null,
            ]);
        }

        return $this->joinParts(collect($row)
            ->reject(fn ($value) => is_array($value) || is_object($value) || $value === null || $value === '')
            ->map(fn ($value, string $key) => str_replace('_', ' ', $key).': '.$this->stringValue($value))
            ->values()
            ->all());
    }

    private function severityPhrase(array $row, string|array $keys, string $suffix): ?string
    {
        foreach ((array) $keys as $key) {
            if (! isset($row[$key]) || trim((string) $row[$key]) === '') {
                continue;
            }

            return trim((string) $row[$key]).' '.$suffix;
        }

        return null;
    }

    private function personPhrase(array $row): ?string
    {
        $parts = [];

        if (! empty($row['sex'])) {
            $parts[] = (string) $row['sex'];
        }

        if (isset($row['age']) && $row['age'] !== '') {
            $parts[] = 'age '.$row['age'];
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function vulnerableCount(array $row): int
    {
        return collect([
            'senior_count',
            'senior_citizens',
            'seniors',
            'elderly_count',
            'pwd_count',
            'persons_with_disability',
            'persons_with_disabilities',
            'pregnant_count',
            'pregnant',
            'pregnant_women',
            'adult_senior_count',
            'adult_pwd_count',
            'children_pwd_count',
            'adult_pregnant_count',
        ])
            ->sum(fn (string $key) => (int) ($row[$key] ?? 0));
    }

    private function countPhrase(array $row, array $keys, string $label, ?string $pluralLabel = null): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                $count = (float) $row[$key];
                $countLabel = $count === 1.0 ? $label : ($pluralLabel ?? $label);

                return sprintf('%s %s', $row[$key], $countLabel);
            }
        }

        return null;
    }

    private function yesNo(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_numeric($value)) {
            return (int) $value === 1 ? 'Yes' : 'No';
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'y'], true) ? 'Yes' : 'No';
    }

    private function joinParts(array $parts): string
    {
        $filtered = collect($parts)
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->values()
            ->all();

        return $filtered === [] ? 'Details reported; verification required.' : implode('; ', $filtered);
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $this->yesNo($value);
        }

        return trim((string) $value);
    }

    private function detailPreset(IncidentTypeDetail $detail): ?string
    {
        $config = $detail->config_json ?? [];

        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        $preset = trim((string) data_get($config, 'preset', ''));

        if ($preset !== '') {
            return $preset;
        }

        $inputType = trim((string) $detail->input_type);
        $legacyPresets = [
            'casualtyPatient',
            'family',
            'person',
            'vehicleInvolved',
            'roadAccessStatus',
            'infrastructureDamage',
            'shelterDamage',
        ];

        return in_array($inputType, $legacyPresets, true) ? $inputType : null;
    }

    private function detailSearchText(IncidentTypeDetail $detail): string
    {
        return strtolower($detail->field_label.' '.$detail->field_key.' '.$detail->unit.' '.($this->detailPreset($detail) ?? ''));
    }

    private function detailHasPreset(IncidentTypeDetail $detail, array $presets): bool
    {
        return in_array($this->detailPreset($detail), $presets, true);
    }

    private function classifyDetail(IncidentTypeDetail $detail): string
    {
        $label = $this->detailSearchText($detail);
        $inputType = strtolower((string) $detail->input_type);
        $preset = $this->detailPreset($detail);

        if ($preset === 'roadAccessStatus') {
            return 'gaps';
        }

        if (in_array($preset, ['infrastructureDamage', 'shelterDamage', 'vehicleInvolved'], true)) {
            return 'damage';
        }

        if (in_array($preset, ['casualtyPatient', 'family', 'person'], true)) {
            return 'population';
        }

        if (str_contains($label, 'road') || str_contains($label, 'access')) {
            return 'gaps';
        }

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

    private function executiveAssessment(array $context): string
    {
        $currentCount = $context['current_incidents']->count();

        if ($currentCount === 0) {
            return 'No active/deferred incident reports remain in the current operating picture. Resolved reports are treated as addressed history, and discarded reports are excluded.';
        }

        $areas = $context['current_location_counts']->keys()->take(5)->implode(', ');
        $types = $context['current_type_counts']->keys()->take(4)->implode(', ');
        $openAssignments = $context['current_team_assignments']
            ->filter(fn ($assignment) => ! in_array(strtolower((string) $assignment->status), ['completed', 'cancelled'], true))
            ->count();
        $openResources = $context['current_resource_needs']->sum('quantity');
        $lifeSafety = $this->lifeSafetySummary($context);
        $access = $this->accessSummary($context);

        return sprintf(
            '%d active/deferred incident report%s %s open across %s. %s %s Current pressure is driven by %s, with %d in-progress assignment%s and %d current requested resource unit%s associated with open reports.',
            $currentCount,
            $currentCount === 1 ? '' : 's',
            $currentCount === 1 ? 'remains' : 'remain',
            $areas !== '' ? $areas : 'mapped Hotline areas',
            $lifeSafety['assessment_sentence'],
            $access['assessment_sentence'],
            $types !== '' ? $types : 'reported incident activity',
            $openAssignments,
            $openAssignments === 1 ? '' : 's',
            $openResources,
            $openResources === 1 ? '' : 's',
        );
    }

    private function lifeSafetySummary(array $context): array
    {
        $populationItems = collect($this->detailsForSection($context['current_field_details'], 'population'));
        $people = $populationItems->sum(fn (array $item): int => $this->populationCount($item, [
            'member_count',
            'individuals',
            'people',
            'persons',
            'affected_persons',
            'persons_affected',
        ]));
        $families = $populationItems->sum(fn (array $item): int => $this->populationCount($item, [
            'families',
            'affected_families',
            'families_affected',
        ]));
        $children = $populationItems->sum(fn (array $item): int => $this->populationCount($item, [
            'children',
            'children_count',
        ]));
        $seniors = $populationItems->sum(fn (array $item): int => $this->populationCount($item, [
            'senior_citizens',
            'senior_count',
            'seniors',
            'elderly_count',
        ]));
        $displacementSignals = $populationItems
            ->filter(fn (array $item): bool => str_contains(strtolower($item['label'].' '.$item['key'].' '.$item['value']), 'displac')
                || $this->yesNo(data_get($item, 'source.displaced', false)) === 'Yes'
                || $this->yesNo(data_get($item, 'source.temporary_shelter_needed', false)) === 'Yes')
            ->pluck('incident_id')
            ->unique()
            ->count();
        $injurySignals = $populationItems
            ->filter(fn (array $item): bool => str_contains(strtolower($item['label'].' '.$item['key'].' '.$item['value']), 'patient')
                || str_contains(strtolower($item['label'].' '.$item['key'].' '.$item['value']), 'injur')
                || str_contains(strtolower($item['label'].' '.$item['key'].' '.$item['value']), 'triage'))
            ->pluck('incident_id')
            ->unique()
            ->count();
        $missingSignals = $context['current_type_counts']->keys()
            ->filter(fn (string $type): bool => str_contains(strtolower($type), 'missing'))
            ->isNotEmpty()
            ? 1
            : $populationItems
                ->filter(fn (array $item): bool => str_contains(strtolower($item['label'].' '.$item['key']), 'missing'))
                ->pluck('incident_id')
                ->unique()
                ->count();
        $strandedSignals = $context['current_type_counts']->keys()
            ->filter(fn (string $type): bool => str_contains(strtolower($type), 'rescue'))
            ->isNotEmpty()
            ? 1
            : $populationItems
                ->filter(fn (array $item): bool => str_contains(strtolower($item['label'].' '.$item['key'].' '.$item['value']), 'stranded'))
                ->pluck('incident_id')
                ->unique()
                ->count();
        $criticalHigh = $context['current_incidents']
            ->filter(fn (Incident $incident): bool => in_array(strtolower((string) ($incident->alert_level?->value ?? $incident->alert_level ?? '')), ['critical', 'elevated', 'high'], true))
            ->count();

        $value = $people > 0 || $families > 0
            ? $this->joinParts([
                $people > 0 ? sprintf('%d people', $people) : null,
                $families > 0 ? sprintf('%d %s', $families, $families === 1 ? 'family' : 'families') : null,
            ])
            : sprintf('%d life-safety signal%s', $populationItems->count(), $populationItems->count() === 1 ? '' : 's');

        $riskParts = [
            $criticalHigh > 0 ? sprintf('%d critical/high-priority report%s', $criticalHigh, $criticalHigh === 1 ? '' : 's') : null,
            $displacementSignals > 0 ? sprintf('%d displacement signal%s', $displacementSignals, $displacementSignals === 1 ? '' : 's') : null,
            $injurySignals > 0 ? sprintf('%d injury/patient signal%s', $injurySignals, $injurySignals === 1 ? '' : 's') : null,
            $missingSignals > 0 ? sprintf('%d missing-person signal%s', $missingSignals, $missingSignals === 1 ? '' : 's') : null,
            $strandedSignals > 0 ? sprintf('%d rescue/stranded signal%s', $strandedSignals, $strandedSignals === 1 ? '' : 's') : null,
        ];
        $breakdownParts = [
            $children > 0 ? sprintf('%d children declared', $children) : null,
            $seniors > 0 ? sprintf('%d seniors declared', $seniors) : null,
        ];
        $hasSignal = $populationItems->isNotEmpty() || $criticalHigh > 0 || $missingSignals > 0 || $strandedSignals > 0;
        $note = $this->joinParts(array_merge($riskParts, $breakdownParts));

        return [
            'has_signal' => $hasSignal,
            'value' => $hasSignal ? $value : 'No current life-safety field reported',
            'note' => $hasSignal ? $note : 'No active/deferred population, injury, displacement, missing-person, or rescue field is present in configured data.',
            'watch_item' => $hasSignal ? 'Life-safety signals remain in the current operating picture.' : null,
            'assessment_sentence' => $hasSignal
                ? sprintf('Life-safety signals include %s.', strtolower($note))
                : 'No current life-safety signal is reported in configured fields.',
            'decision_point' => $hasSignal
                ? sprintf('%s remain in the current picture; leadership may need to prioritize rescue, EMS, welfare, shelter, and protection coordination before ordinary logistics.', $note)
                : 'No current life-safety decision pressure was detected from configured fields.',
        ];
    }

    private function accessSummary(array $context): array
    {
        $roadRows = $this->roadAccessRows($context['current_field_details']);
        $blocked = $roadRows->where('status', 'Blocked')->count();
        $limited = $roadRows->where('status', 'Limited')->count();
        $total = $blocked + $limited;

        return [
            'value' => $total > 0 ? sprintf('%d blocked / %d limited', $blocked, $limited) : 'No current access constraint reported',
            'note' => $total > 0
                ? 'Reported access constraints may affect whether responders can reach affected people quickly.'
                : 'No blocked or limited route report is present in configured road/access fields.',
            'watch_item' => $total > 0 ? sprintf('%d blocked and %d limited access report%s may affect response movement.', $blocked, $limited, $total === 1 ? '' : 's') : null,
            'assessment_sentence' => $total > 0
                ? sprintf('Access to help may be affected by %d blocked and %d limited route report%s.', $blocked, $limited, $total === 1 ? '' : 's')
                : 'No blocked or limited access report is present.',
        ];
    }

    private function resolvedProgressSummary(array $context): array
    {
        $resolved = $context['resolved_incidents'];
        $resolvedDuringPeriod = (int) $context['closed_incident_count'];

        if ($resolvedDuringPeriod === 0) {
            return [
                'visible' => false,
                'title' => 'People Helped and Accomplishments',
                'value' => 'No resolved reports yet',
                'note' => 'Resolved reports will appear here once they are addressed during the SITREP period.',
                'highlight_value' => 'No resolved people count yet',
                'highlight_note' => 'Resolved people and family counts will appear here when structured records are available.',
                'handled_note' => 'No incident has been marked resolved during this SITREP period.',
                'deployment_value' => 'No completed deployment count yet',
                'deployment_note' => 'Completed team and resolved-resource counts appear here when available.',
            ];
        }

        $resolvedIds = $resolved->pluck('id')->all();
        $completedAssignments = $context['team_assignments']
            ->filter(fn ($assignment): bool => in_array($assignment->incident_id, $resolvedIds, true))
            ->filter(fn ($assignment): bool => strtolower((string) $assignment->status) === 'completed')
            ->count();
        $resolvedResources = $context['resource_needs']
            ->filter(fn (array $need): bool => in_array($need['incident_id'], $resolvedIds, true))
            ->sum('quantity');
        $resolvedPopulation = $this->resolvedPopulationSummary($context, $resolvedIds);
        $types = $context['resolved_type_counts']->keys()->take(3)->implode(', ');
        $handledNote = $this->joinParts([
            $types !== '' ? sprintf('Handled: %s', $types) : null,
            'Resolved reports are addressed history and excluded from current pressure.',
        ]);
        $deploymentValue = $this->joinParts([
            $completedAssignments > 0 ? sprintf('%d completed team assignment%s', $completedAssignments, $completedAssignments === 1 ? '' : 's') : null,
            $resolvedResources > 0 ? sprintf('%d resource unit%s', $resolvedResources, $resolvedResources === 1 ? '' : 's') : null,
        ]);

        return [
            'visible' => true,
            'title' => 'People Helped and Accomplishments',
            'value' => sprintf('%d resolved report%s', $resolvedDuringPeriod, $resolvedDuringPeriod === 1 ? '' : 's'),
            'highlight_value' => $resolvedPopulation['highlight_value'],
            'highlight_note' => $resolvedPopulation['highlight_note'],
            'handled_note' => $handledNote,
            'deployment_value' => $deploymentValue,
            'deployment_note' => 'Completed team assignments and resource units tied to resolved reports are no longer counted as current demand.',
            'note' => $this->joinParts([
                $types !== '' ? sprintf('Handled: %s', $types) : null,
                $resolvedPopulation['note'] !== '' ? $resolvedPopulation['note'] : null,
                $completedAssignments > 0 ? sprintf('%d completed team assignment%s', $completedAssignments, $completedAssignments === 1 ? '' : 's') : null,
                $resolvedResources > 0 ? sprintf('%d resource unit%s no longer counted as current demand', $resolvedResources, $resolvedResources === 1 ? '' : 's') : null,
            ]),
        ];
    }

    private function resolvedPopulationSummary(array $context, array $resolvedIds): array
    {
        $totals = [
            'patients' => 0,
            'families' => 0,
            'people' => 0,
            'children' => 0,
            'seniors' => 0,
            'pregnant' => 0,
            'pwd' => 0,
        ];

        $context['field_details']
            ->filter(fn (IncidentTypeDetail $detail): bool => in_array($detail->incident_id, $resolvedIds, true))
            ->each(function (IncidentTypeDetail $detail) use (&$totals): void {
                $rows = $this->decodedDetailRows($detail);

                if ($rows === []) {
                    return;
                }

                if ($this->detailHasPreset($detail, ['casualtyPatient'])) {
                    $totals['patients'] += count($rows);

                    return;
                }

                if (! $this->detailHasPreset($detail, ['family'])) {
                    return;
                }

                foreach ($rows as $row) {
                    $totals['families'] += $this->firstNumericValue($row, ['families']);
                    $totals['people'] += $this->firstNumericValue($row, ['individuals', 'people', 'persons', 'member_count']);
                    $totals['children'] += $this->firstNumericValue($row, ['children', 'children_count']);
                    $totals['seniors'] += $this->firstNumericValue($row, ['senior_citizens', 'senior_count', 'seniors', 'elderly_count']);
                    $totals['pregnant'] += $this->firstNumericValue($row, ['pregnant', 'pregnant_count', 'pregnant_women']);
                    $totals['pwd'] += $this->firstNumericValue($row, ['persons_with_disability', 'persons_with_disabilities', 'pwd', 'pwd_count']);
                }
            });

        $parts = [];

        if ($totals['people'] > 0 || $totals['families'] > 0) {
            $addressed = collect([
                $totals['families'] > 0 ? sprintf('%d %s', $totals['families'], $totals['families'] === 1 ? 'family' : 'families') : null,
                $totals['people'] > 0 ? sprintf('%d people', $totals['people']) : null,
            ])
                ->filter()
                ->implode(' / ');

            $parts[] = $addressed.' addressed';
        }

        if ($totals['patients'] > 0) {
            $parts[] = sprintf('%d patient record%s', $totals['patients'], $totals['patients'] === 1 ? '' : 's');
        }

        $breakdown = collect([
            $totals['children'] > 0 ? sprintf('%d children', $totals['children']) : null,
            $totals['seniors'] > 0 ? sprintf('%d %s', $totals['seniors'], $totals['seniors'] === 1 ? 'senior' : 'seniors') : null,
            $totals['pregnant'] > 0 ? sprintf('%d pregnant', $totals['pregnant']) : null,
            $totals['pwd'] > 0 ? sprintf('%d PWD', $totals['pwd']) : null,
        ])
            ->filter()
            ->implode(', ');

        if ($breakdown !== '') {
            $parts[] = $breakdown.' declared in resolved family records';
        }

        $highlightValue = $totals['people'] > 0
            ? sprintf('%d people helped', $totals['people'])
            : ($totals['patients'] > 0 ? sprintf('%d patient record%s addressed', $totals['patients'], $totals['patients'] === 1 ? '' : 's') : 'Resolved reports recorded');

        $highlightNote = $this->joinParts([
            $totals['families'] > 0 ? sprintf('%d %s assisted or returned home', $totals['families'], $totals['families'] === 1 ? 'family' : 'families') : null,
            $totals['patients'] > 0 ? sprintf('%d patient record%s addressed', $totals['patients'], $totals['patients'] === 1 ? '' : 's') : null,
            $breakdown !== '' ? $breakdown.' in resolved family records' : null,
        ]);

        return [
            'note' => $this->joinParts($parts),
            'highlight_value' => $highlightValue,
            'highlight_note' => $highlightNote,
        ];
    }

    private function firstNumericValue(array $row, array $keys): int
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (int) $row[$key];
            }
        }

        return 0;
    }

    private function populationCount(array $item, array $keys): int
    {
        foreach ($keys as $key) {
            $value = data_get($item, 'source.'.$key);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        if (in_array('member_count', $keys, true) && is_numeric($item['value'] ?? null)) {
            return (int) $item['value'];
        }

        return 0;
    }

    private function decisionPoints(array $context): array
    {
        $points = [];
        $resourceTotal = $context['current_resource_needs']->sum('quantity');
        $blockedRoutes = $this->roadAccessRows($context['current_field_details'])
            ->where('status', 'Blocked')
            ->count();
        $limitedRoutes = $this->roadAccessRows($context['current_field_details'])
            ->where('status', 'Limited')
            ->count();
        $missingPeople = $this->detailsForSection($context['current_field_details'], 'population');
        $lifeSafety = $this->lifeSafetySummary($context);

        if ($lifeSafety['has_signal']) {
            $points[] = [
                'title' => 'Life safety',
                'body' => $lifeSafety['decision_point'],
            ];
        }

        if (($blockedRoutes + $limitedRoutes) > 0) {
            $points[] = [
                'title' => 'Access to help',
                'body' => sprintf('%d blocked and %d limited route report%s may affect whether rescue, EMS, welfare, utilities, or public safety teams can reach people quickly.', $blockedRoutes, $limitedRoutes, ($blockedRoutes + $limitedRoutes) === 1 ? '' : 's'),
            ];
        }

        if ($resourceTotal > 0) {
            $points[] = [
                'title' => 'Resource posture',
                'body' => sprintf('Current requested demand totals %d units across active/deferred reports. Leadership may need to consider whether existing response capacity is enough for open life-safety, welfare, public safety, infrastructure, and water-support needs.', $resourceTotal),
            ];
        }

        if (! $lifeSafety['has_signal'] && collect($missingPeople)->contains(fn (array $item) => str_contains(strtolower($item['label'].' '.$item['key']), 'missing'))) {
            $points[] = [
                'title' => 'Life-safety visibility',
                'body' => 'A missing-person signal remains in the current operating picture. Leadership may need to ensure search coordination remains visible until the report is resolved.',
            ];
        }

        if (empty($points)) {
            $points[] = [
                'title' => 'Operating posture',
                'body' => 'No specific decision pressure was detected from configured current incident data.',
            ];
        }

        return $points;
    }

    private function currentOperatingPicture(array $context): array
    {
        $activeCount = (int) ($context['status_counts'][IncidentStatus::Active->value] ?? 0);
        $deferredCount = (int) ($context['status_counts'][IncidentStatus::Deferred->value] ?? 0);
        $openAssignments = $context['current_team_assignments']
            ->filter(fn ($assignment) => ! in_array(strtolower((string) $assignment->status), ['completed', 'cancelled'], true))
            ->count();
        $openResources = $context['current_resource_needs']->sum('quantity');

        return [
            'open_reports' => $context['current_incidents']->count(),
            'active_reports' => $activeCount,
            'deferred_reports' => $deferredCount,
            'current_assignments' => $openAssignments,
            'current_resource_units' => $openResources,
            'status_note' => 'Deferred reports remain open. They indicate response or coordination is underway and the citizen/operator interaction may resume when field response arrives or more information is available.',
        ];
    }

    private function areasOfConcern(array $context): array
    {
        return $context['current_incidents']
            ->map(function (Incident $incident) use ($context): array {
                $types = $incident->incidentTypes->pluck('name')->values()->all();
                $resources = $context['current_resource_needs']
                    ->where('incident_id', $incident->id)
                    ->sum('quantity');
                $assignments = $context['current_team_assignments']
                    ->where('incident_id', $incident->id)
                    ->count();

                return [
                    'incident_id' => $incident->id,
                    'area' => $this->areaLabel($incident),
                    'status' => $this->statusLabel($incident->status),
                    'types' => $types,
                    'summary' => sprintf(
                        '%s report involving %s. %d assignment%s and %d requested resource unit%s are associated with this open report.',
                        $this->statusLabel($incident->status),
                        count($types) > 0 ? implode(', ', $types) : 'unclassified activity',
                        $assignments,
                        $assignments === 1 ? '' : 's',
                        $resources,
                        $resources === 1 ? '' : 's',
                    ),
                ];
            })
            ->values()
            ->all();
    }

    private function concernGroups(array $context): array
    {
        $roadRows = $this->roadAccessRows($context['current_field_details'])->groupBy('incident_id');

        return $context['current_incidents']
            ->groupBy(fn (Incident $incident): string => $this->concernGroupFor($incident))
            ->map(function (Collection $incidents, string $concern) use ($context, $roadRows): array {
                $incidentIds = $incidents->pluck('id')->values();
                $resources = $context['current_resource_needs']
                    ->whereIn('incident_id', $incidentIds->all());
                $assignments = $context['current_team_assignments']
                    ->whereIn('incident_id', $incidentIds->all())
                    ->filter(fn ($assignment) => ! in_array(strtolower((string) $assignment->status), ['completed', 'cancelled'], true))
                    ->count();
                $types = $incidents
                    ->flatMap(fn (Incident $incident) => $incident->incidentTypes->pluck('name'))
                    ->unique()
                    ->values();
                $areas = $incidents
                    ->map(fn (Incident $incident) => $this->areaLabel($incident))
                    ->unique()
                    ->values();
                $accessRows = $incidentIds
                    ->flatMap(fn (int $id) => $roadRows->get($id, collect()))
                    ->filter(fn (array $row) => ! in_array(strtolower((string) $row['status']), ['', 'open', 'clear', 'cleared', 'passable'], true))
                    ->values();

                return [
                    'concern' => $concern,
                    'open_reports' => $incidents->count(),
                    'areas' => $areas->all(),
                    'incident_types' => $types->all(),
                    'current_assignments' => $assignments,
                    'resource_units' => $resources->sum('quantity'),
                    'source_incidents' => $incidentIds->all(),
                    'main_signals' => $this->concernSignals($types, $accessRows, $resources),
                ];
            })
            ->sortBy(fn (array $group): array => [
                -1 * (int) $group['open_reports'],
                -1 * (int) $group['resource_units'],
                $group['concern'],
            ])
            ->values()
            ->all();
    }

    private function concernGroupFor(Incident $incident): string
    {
        $types = $incident->incidentTypes
            ->pluck('name')
            ->map(fn (string $type): string => strtolower($type))
            ->all();

        if ($this->typeMatches($types, ['building/house fire'])) {
            return 'Fire and Shelter Damage';
        }

        if ($this->typeMatches($types, ['missing person'])) {
            return 'Search and Missing Person';
        }

        if ($this->typeMatches($types, ['flood', 'rescue', 'family displacement'])) {
            return 'Flood, Rescue, and Displacement';
        }

        if ($this->typeMatches($types, ['road accident', 'animal attack'])) {
            return 'Medical and Injury Response';
        }

        if ($this->typeMatches($types, ['public disturbance', 'robbery', 'domestic violence', 'bomb threat'])) {
            return 'Public Safety and Protection';
        }

        if ($this->typeMatches($types, ['infrastructure damage', 'landslide', 'earthquake', 'power outage', 'water supply issue', 'shelter damage'])) {
            return 'Infrastructure, Access, and Utilities';
        }

        return 'Other Current Concerns';
    }

    private function typeMatches(array $types, array $needles): bool
    {
        foreach ($types as $type) {
            foreach ($needles as $needle) {
                if (str_contains($type, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function concernSignals(Collection $types, Collection $accessRows, Collection $resources): string
    {
        $signals = [];
        $blocked = $accessRows->filter(fn (array $row) => strtolower((string) $row['status']) === 'blocked')->count();
        $limited = $accessRows->filter(fn (array $row) => strtolower((string) $row['status']) === 'limited')->count();

        if ($blocked > 0 || $limited > 0) {
            $parts = [];

            if ($blocked > 0) {
                $parts[] = sprintf('%d blocked access %s', $blocked, $blocked === 1 ? 'point' : 'points');
            }

            if ($limited > 0) {
                $parts[] = sprintf('%d limited access %s', $limited, $limited === 1 ? 'point' : 'points');
            }

            $signals[] = implode(', ', $parts);
        }

        $topTypes = $types->take(3)->implode(', ');

        if ($topTypes !== '') {
            $signals[] = 'Types: '.$topTypes;
        }

        $topResources = $resources
            ->sortByDesc('quantity')
            ->take(3)
            ->pluck('name')
            ->unique()
            ->implode(', ');

        if ($topResources !== '') {
            $signals[] = 'Key needs: '.$topResources;
        }

        return implode('; ', $signals) ?: 'Current incident signal requires review.';
    }

    private function periodActivity(array $context): array
    {
        return [
            'total_reports' => $context['incidents']->count(),
            'open_at_close' => $context['current_incidents']->count(),
            'resolved_during_period' => $context['closed_incident_count'],
            'discarded_excluded' => $context['discarded_incident_count'],
            'note' => 'Resolved reports are treated as addressed history. Discarded reports are excluded from operational posture and demand.',
        ];
    }

    private function verificationNotes(array $context): array
    {
        $notes = [
            'Resource figures represent requested needs from active/deferred incident records; they do not confirm actual supply, dispatch, delivery, or consumption.',
            'Population fields may overlap across shelter, family, patient, and crowd records; consolidated totals should be verified before external use.',
            'Road and access constraints are based on incident report fields and should be verified before public routing advisories.',
        ];

        if ($context['discarded_incident_count'] > 0) {
            $notes[] = sprintf('%d discarded report%s excluded from current posture, demand, and severity.', $context['discarded_incident_count'], $context['discarded_incident_count'] === 1 ? ' was' : 's were');
        }

        return $notes;
    }

    private function resourceCategoryGroups(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $resource = (string) ($row['resource'] ?? $row['name'] ?? 'Resource');
            $quantity = (int) ($row['quantity_requested'] ?? $row['quantity'] ?? 0);
            $group = (string) ($row['category'] ?? 'Uncategorized');
            $groups[$group]['category'] = $group;
            $groups[$group]['quantity_requested'] = ($groups[$group]['quantity_requested'] ?? 0) + $quantity;
            $groups[$group]['resources'][] = $resource;
        }

        return array_values(array_map(function (array $group): array {
            $group['resources'] = array_values(array_unique($group['resources'] ?? []));

            return $group;
        }, $groups));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resourceGapEvidenceRows(array $context): array
    {
        $routeRows = $this->roadAccessRows($context['current_field_details'])
            ->groupBy('incident_id');
        $populationItems = collect($this->detailsForSection($context['current_field_details'], 'population'))
            ->groupBy('incident_id');

        return $context['current_resource_needs']
            ->groupBy(fn (array $item): string => implode('|', [
                (string) ($item['location_name'] ?? $item['source_hub_name'] ?? 'Current Location'),
                (string) ($item['resource_type_id'] ?? 0),
            ]))
            ->map(function (Collection $items) use ($routeRows, $populationItems): array {
                $first = $items->first();
                $incidentIds = $items
                    ->pluck('incident_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'kind' => 'resource_need',
                    'resource_type_id' => $first['resource_type_id'] ?? null,
                    'resource_type_name' => $first['resource_type_name'] ?? ($first['name'] ?? 'Resource'),
                    'resource_type_category_id' => $first['resource_type_category_id'] ?? ($first['category_id'] ?? null),
                    'resource_type_category_name' => $first['resource_type_category_name'] ?? ($first['category'] ?? 'Uncategorized'),
                    'quantity' => $items->sum('quantity'),
                    'quantity_requested' => $items->sum('quantity'),
                    'unit_label' => $first['unit_label'] ?? ($first['unit'] ?? 'units'),
                    'location_name' => $first['location_name'] ?? ($first['source_hub_name'] ?? null),
                    'source_hub_name' => $first['source_hub_name'] ?? ($first['location_name'] ?? null),
                    'incident_ids' => $incidentIds,
                    'incident_refs' => array_map(fn (int|string $id): array => ['id' => $id], $incidentIds),
                    'routes' => $this->linkedRouteEvidence($incidentIds, $routeRows),
                    'population' => $this->linkedPopulationEvidence($incidentIds, $populationItems),
                ];
            })
            ->sortBy([
                ['location_name', 'asc'],
                ['resource_type_category_name', 'asc'],
                ['resource_type_name', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $incidentIds
     * @param  Collection<int|string, Collection<int, array<string, mixed>>>  $routeRows
     * @return array<int, array<string, mixed>>
     */
    private function linkedRouteEvidence(array $incidentIds, Collection $routeRows): array
    {
        return collect($incidentIds)
            ->flatMap(fn (int|string $id): Collection => $routeRows->get($id, collect()))
            ->filter(fn (array $row): bool => trim((string) ($row['route_location'] ?? '')) !== '')
            ->map(fn (array $row): array => [
                'route_location' => $row['route_location'] ?? '',
                'status' => $row['status'] ?? '',
                'obstruction_type' => $row['obstruction_type'] ?? '',
                'cleared' => $row['cleared'] ?? '',
                'incident_ids' => array_values(array_filter([(int) ($row['incident_id'] ?? 0)])),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $incidentIds
     * @param  Collection<int|string, Collection<int, array<string, mixed>>>  $populationItems
     * @return array<int, array<string, mixed>>
     */
    private function linkedPopulationEvidence(array $incidentIds, Collection $populationItems): array
    {
        return collect($incidentIds)
            ->flatMap(fn (int|string $id): Collection => $populationItems->get($id, collect()))
            ->groupBy(fn (array $item): string => (string) ($item['label'] ?? 'Population/life-safety records'))
            ->map(function (Collection $group, string $signal): array {
                return [
                    'signal' => $signal,
                    'reports' => $group->pluck('incident_id')->unique()->count(),
                    'people' => $this->populationPeopleCount($group),
                    'notes' => $this->populationEvidenceNotes($group),
                    'incident_ids' => $group->pluck('incident_id')->unique()->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function populationPeopleCount(Collection $group): int
    {
        $declaredPeople = $group->sum(fn (array $item): int => (int) data_get($item, 'source.member_count', 0));

        return $declaredPeople > 0 ? $declaredPeople : $group->count();
    }

    /**
     * @return array<int, string>
     */
    private function populationEvidenceNotes(Collection $group): array
    {
        $parts = [];

        $conditions = $group
            ->pluck('source.condition')
            ->filter()
            ->map(fn (mixed $condition): string => trim((string) $condition))
            ->filter()
            ->unique()
            ->values()
            ->all();
        array_push($parts, ...$conditions);

        $families = $group->sum(fn (array $item): int => (int) data_get($item, 'source.families', 0));
        if ($families > 0) {
            $parts[] = sprintf('%d %s', $families, $families === 1 ? 'family' : 'families');
        }

        $displaced = $group->filter(fn (array $item): bool => $this->yesNo(data_get($item, 'source.displaced', false)) === 'Yes')->count();
        if ($displaced > 0) {
            $parts[] = sprintf('%d displacement signal%s', $displaced, $displaced === 1 ? '' : 's');
        }

        foreach ($this->populationBreakdowns($group) as $breakdown) {
            $parts[] = sprintf('%d %s', $breakdown['count'], strtolower((string) $breakdown['breakdown']));
        }

        return collect($parts)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function roadAccessRows(Collection $details): Collection
    {
        return $details
            ->filter(fn (IncidentTypeDetail $detail) => $this->detailPreset($detail) === 'roadAccessStatus'
                || str_contains(strtolower($detail->field_label.' '.$detail->field_key), 'road')
                || str_contains(strtolower($detail->field_label.' '.$detail->field_key), 'access'))
            ->flatMap(function (IncidentTypeDetail $detail): array {
                return collect($this->decodedDetailRows($detail))
                    ->map(fn (array $row): array => [
                        'incident_id' => $detail->incident_id,
                        'status' => (string) ($row['status'] ?? ''),
                        'route_location' => (string) ($row['route_location'] ?? $row['location'] ?? $row['description'] ?? ''),
                        'obstruction_type' => (string) ($row['obstruction_type'] ?? ''),
                        'cleared' => array_key_exists('cleared', $row) ? $this->yesNo($row['cleared']) : '',
                    ])
                    ->all();
            })
            ->values();
    }

    private function buildHeadline(array $context, string $coverageArea): string
    {
        $currentCount = $context['current_incidents']->count();

        if ($currentCount === 0) {
            return 'No active incident pressure remains in the current operating picture';
        }

        $types = $context['current_type_counts']->keys()->take(3)->implode(', ');
        $areas = $context['current_location_counts']->keys()->take(3)->implode(', ');

        if ($currentCount === 1) {
            return sprintf('%s report remains open in %s', $types ?: 'Incident', $areas ?: $coverageArea);
        }

        return sprintf('Multi-incident response posture remains open across %s', $areas ?: $coverageArea);
    }

    private function currentHotspotLabel(array $context, string $fallback): string
    {
        $counts = $context['current_location_counts'];
        $topCount = (int) ($counts->first() ?? 0);
        $secondCount = (int) ($counts->values()->get(1) ?? 0);

        if ($topCount > 0 && $topCount > $secondCount) {
            return (string) $counts->keys()->first();
        }

        return $counts->keys()->take(5)->implode(', ') ?: $fallback;
    }

    private function hotspotNote(array $context): string
    {
        $counts = $context['current_location_counts'];
        $topCount = (int) ($counts->first() ?? 0);
        $secondCount = (int) ($counts->values()->get(1) ?? 0);

        if ($topCount > 0 && $topCount > $secondCount) {
            return 'Current area is identified from active/deferred incident concentration.';
        }

        return 'No single current hotspot is identified; active/deferred concerns are distributed across multiple areas.';
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

    private function isCurrentIncident(Incident $incident): bool
    {
        return in_array($this->statusValue($incident->status), [
            IncidentStatus::Active->value,
            IncidentStatus::Deferred->value,
        ], true);
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
