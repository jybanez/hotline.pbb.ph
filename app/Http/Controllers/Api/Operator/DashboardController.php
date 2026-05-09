<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Calls\Models\CallAttemptOperatorAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentTransfer;
use App\Domain\Shared\Enums\TeamAssignmentStatus;
use App\Domain\Teams\Models\TeamAssignment;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\OperatorRuntimeState;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Sessions\AvailabilityService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {
    }

    public function show(): JsonResponse
    {
        $operator = request()->user();

        $activeIncidentCount = Incident::query()
            ->where('operator_id', $operator->id)
            ->whereIn('status', [IncidentStatus::Active, IncidentStatus::Deferred])
            ->count();

        $archivedIncidentCount = Incident::query()
            ->where('operator_id', $operator->id)
            ->whereIn('status', [IncidentStatus::Resolved, IncidentStatus::Discarded])
            ->count();

        $incomingNewCallCount = CallAttemptOperatorAttempt::query()
            ->where('operator_id', $operator->id)
            ->where('status', 'calling')
            ->count();

        $incomingReconnectCount = CallSession::query()
            ->where('status', 'calling')
            ->whereHas('incident', fn ($query) => $query->where('operator_id', $operator->id))
            ->count();

        $pendingTransfers = IncidentTransfer::query()
            ->with(['fromOperator', 'incident'])
            ->where('to_operator_id', $operator->id)
            ->where('status', 'requested')
            ->latest('id')
            ->get()
            ->map(fn (IncidentTransfer $transfer) => [
                'id' => $transfer->id,
                'incident_id' => $transfer->incident_id,
                'display_id' => str_pad((string) $transfer->incident_id, 6, '0', STR_PAD_LEFT),
                'reason' => $transfer->reason,
                'requested_at' => $transfer->requested_at?->toIso8601String(),
                'from_operator' => $transfer->fromOperator ? [
                    'id' => $transfer->fromOperator->id,
                    'name' => $transfer->fromOperator->name,
                    'avatar' => $transfer->fromOperator->avatar,
                ] : null,
            ])
            ->values()
            ->all();

        $availableTransferTargets = User::query()
            ->where('role', UserRole::Operator)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($operator->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->availability->operatorRuntimeState($user) === OperatorRuntimeState::Available->value)
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'runtime_state' => OperatorRuntimeState::Available->value,
            ])
            ->values()
            ->all();

        $teamAssignmentLanes = $this->buildTeamAssignmentLanes();
        $openAssignmentCount = $this->countOpenTeamAssignments($operator);
        $runtimeState = $this->availability->operatorRuntimeState($operator);

        return response()->json([
            'operator_runtime_state' => $runtimeState,
            'pending_transfer_requests' => $pendingTransfers,
            'available_transfer_targets' => $availableTransferTargets,
            'team_assignment_lanes' => $teamAssignmentLanes,
            'stat_chips' => [
                [
                    'label' => 'State',
                    'value' => $runtimeState,
                ],
                [
                    'label' => 'Active',
                    'value' => $activeIncidentCount,
                ],
                [
                    'label' => 'Archive',
                    'value' => $archivedIncidentCount,
                ],
                [
                    'label' => 'Incoming',
                    'value' => $incomingNewCallCount + $incomingReconnectCount,
                ],
                [
                    'label' => 'Transfers',
                    'value' => count($pendingTransfers),
                ],
                [
                    'label' => 'Assignments',
                    'value' => $openAssignmentCount,
                ],
            ],
        ]);
    }

    public function activity(): JsonResponse
    {
        return response()->json([
            'items' => $this->buildActivityItems(request()->user()),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTeamAssignmentLanes(): array
    {
        $statusOrder = [
            TeamAssignmentStatus::Assigned->value,
            TeamAssignmentStatus::Requested->value,
            TeamAssignmentStatus::Accepted->value,
            TeamAssignmentStatus::EnRoute->value,
            TeamAssignmentStatus::OnScene->value,
            TeamAssignmentStatus::Completed->value,
            TeamAssignmentStatus::Cancelled->value,
        ];

        return collect($statusOrder)
            ->map(function (string $status): array {
                return [
                    'id' => $this->teamAssignmentLaneId($status),
                    'title' => $status,
                ];
            })
            ->values()
            ->all();
    }

    private function countOpenTeamAssignments(User $operator): int
    {
        return TeamAssignment::query()
            ->whereIn(
                'incident_id',
                Incident::query()
                    ->select('id')
                    ->where('operator_id', $operator->id)
                    ->whereIn('status', [IncidentStatus::Active, IncidentStatus::Deferred]),
            )
            ->whereNotIn('status', [
                TeamAssignmentStatus::Completed->value,
                TeamAssignmentStatus::Cancelled->value,
            ])
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildActivityItems(User $operator): array
    {
        $incidentEvents = Incident::query()
            ->where('operator_id', $operator->id)
            ->latest('created_at')
            ->limit(12)
            ->get()
            ->map(function (Incident $incident): array {
                $displayId = str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT);
                $status = $incident->status->value;

                return [
                    'incident_id' => $incident->id,
                    'title' => "Incident #{$displayId}",
                    'description' => $status === IncidentStatus::Active->value
                        ? 'Incident assigned to your queue.'
                        : "Incident currently marked {$status}.",
                    'kind' => 'incident',
                    'created_at' => $incident->updated_at?->toIso8601String() ?? $incident->created_at?->toIso8601String(),
                    'sort_at' => $incident->updated_at ?? $incident->created_at,
                ];
            });

        $transferEvents = IncidentTransfer::query()
            ->with(['fromOperator:id,name', 'toOperator:id,name'])
            ->where(function ($query) use ($operator): void {
                $query->where('from_operator_id', $operator->id)
                    ->orWhere('to_operator_id', $operator->id);
            })
            ->latest('updated_at')
            ->limit(12)
            ->get()
            ->map(function (IncidentTransfer $transfer) use ($operator): array {
                $displayId = str_pad((string) $transfer->incident_id, 6, '0', STR_PAD_LEFT);
                $fromName = $transfer->fromOperator?->name ?? 'Unknown operator';
                $toName = $transfer->toOperator?->name ?? 'Unknown operator';

                if ((int) $transfer->to_operator_id === (int) $operator->id && $transfer->status === 'requested') {
                    $description = "Transfer requested by {$fromName}.";
                } elseif ((int) $transfer->from_operator_id === (int) $operator->id && $transfer->status === 'requested') {
                    $description = "Transfer requested to {$toName}.";
                } elseif ($transfer->status === 'accepted') {
                    $description = "Transfer accepted by {$toName}.";
                } elseif ($transfer->status === 'rejected') {
                    $description = "Transfer rejected by {$toName}.";
                } else {
                    $description = "Transfer status updated to {$transfer->status}.";
                }

                return [
                    'incident_id' => $transfer->incident_id,
                    'title' => "Transfer for #{$displayId}",
                    'description' => $description,
                    'kind' => 'transfer',
                    'created_at' => $transfer->updated_at?->toIso8601String() ?? $transfer->requested_at?->toIso8601String(),
                    'sort_at' => $transfer->updated_at ?? $transfer->requested_at,
                ];
            });

        $assignmentEvents = TeamAssignment::query()
            ->with('team:id,name')
            ->whereIn(
                'incident_id',
                Incident::query()
                    ->select('id')
                    ->where('operator_id', $operator->id)
            )
            ->latest('updated_at')
            ->limit(12)
            ->get()
            ->map(function (TeamAssignment $assignment): array {
                $displayId = str_pad((string) $assignment->incident_id, 6, '0', STR_PAD_LEFT);
                $teamName = $assignment->team?->name ?? 'Unknown team';

                return [
                    'incident_id' => $assignment->incident_id,
                    'title' => "Team {$teamName} on #{$displayId}",
                    'description' => "Team assignment is {$assignment->status}.",
                    'kind' => 'team_assignment',
                    'created_at' => $assignment->updated_at?->toIso8601String() ?? $assignment->assigned_at?->toIso8601String(),
                    'sort_at' => $assignment->updated_at ?? $assignment->assigned_at,
                ];
            });

        return collect()
            ->concat($incidentEvents)
            ->concat($transferEvents)
            ->concat($assignmentEvents)
            ->filter(fn (array $item): bool => $item['sort_at'] !== null)
            ->sortByDesc('sort_at')
            ->take(15)
            ->map(function (array $item): array {
                unset($item['sort_at']);

                return $item;
            })
            ->values()
            ->all();
    }

    private function teamAssignmentLaneId(string $status): string
    {
        return str($status)
            ->lower()
            ->replace([' ', '-'], '_')
            ->value();
    }
}
