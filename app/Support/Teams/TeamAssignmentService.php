<?php

namespace App\Support\Teams;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\TeamAssignmentStatus;
use App\Domain\Teams\Models\Team;
use App\Domain\Teams\Models\TeamAssignment;
use App\Domain\Teams\Models\TeamAssignmentAllocatedResource;
use App\Domain\Teams\Models\TeamAssignmentNote;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TeamAssignmentService
{
    private function normalizeStatusValue(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return match (trim((string) $status)) {
            'Assigned', 'assigned' => TeamAssignmentStatus::Assigned->value,
            'Requested', 'requested' => TeamAssignmentStatus::Requested->value,
            'Accepted', 'accepted' => TeamAssignmentStatus::Accepted->value,
            'En-route', 'en_route' => TeamAssignmentStatus::EnRoute->value,
            'On-Scene', 'on_scene' => TeamAssignmentStatus::OnScene->value,
            'Completed', 'completed' => TeamAssignmentStatus::Completed->value,
            'Cancelled', 'cancelled' => TeamAssignmentStatus::Cancelled->value,
            default => trim((string) $status),
        };
    }

    public function assign(User $operator, Incident $incident, int $teamId, ?string $contactPerson = null, array $resources = []): TeamAssignment
    {
        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot manage team assignments for this incident.');
        }

        $team = Team::query()->find($teamId);

        if (! $team) {
            throw new RuntimeException('Selected team is invalid.');
        }

        return DB::transaction(function () use ($operator, $incident, $team, $contactPerson, $resources) {
            $assignment = TeamAssignment::query()->updateOrCreate(
                [
                    'incident_id' => $incident->id,
                    'team_id' => $team->id,
                ],
                [
                    'assigned_by_operator_id' => $operator->id,
                    'status' => TeamAssignmentStatus::Assigned->value,
                    'contact_person' => $contactPerson,
                    'assigned_at' => now(),
                ],
            );

            $assignment->allocatedResources()->delete();

            foreach ($resources as $resource) {
                if (empty($resource['resource_type_id'])) {
                    continue;
                }

                TeamAssignmentAllocatedResource::query()->create([
                    'team_assignment_id' => $assignment->id,
                    'resource_type_id' => (int) $resource['resource_type_id'],
                    'quantity_allocated' => max(1, (int) ($resource['quantity_allocated'] ?? 1)),
                ]);
            }

            return $assignment->fresh(['team', 'allocatedResources.resourceType', 'notes.createdByOperator']);
        });
    }

    public function update(User $operator, TeamAssignment $assignment, array $payload): TeamAssignment
    {
        $assignment->loadMissing('team', 'allocatedResources.resourceType');
        $incident = Incident::query()->findOrFail($assignment->incident_id);

        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot update this team assignment.');
        }

        return DB::transaction(function () use ($operator, $assignment, $payload) {
            $status = $this->normalizeStatusValue($payload['status'] ?? $assignment->status);
            $updates = [
                'status' => $status,
                'contact_person' => $payload['contact_person'] ?? $assignment->contact_person,
            ];

            if ($status === TeamAssignmentStatus::Accepted->value && $assignment->accepted_at === null) {
                $updates['accepted_at'] = now();
            }

            if ($status === TeamAssignmentStatus::EnRoute->value && $assignment->enroute_at === null) {
                $updates['enroute_at'] = now();
            }

            if ($status === TeamAssignmentStatus::OnScene->value && $assignment->arrived_at === null) {
                $updates['arrived_at'] = now();
            }

            if ($status === TeamAssignmentStatus::Completed->value && $assignment->completed_at === null) {
                $updates['completed_at'] = now();
            }

            if ($status === TeamAssignmentStatus::Cancelled->value) {
                $updates['cancelled_from_status'] = $assignment->status;
                $updates['cancel_reason_code'] = $payload['cancel_reason_code'] ?? 'other';
                $updates['cancel_reason_note'] = isset($payload['cancel_reason_note'])
                    ? trim((string) $payload['cancel_reason_note']) ?: null
                    : $assignment->cancel_reason_note;
                $updates['cancelled_by_operator_id'] = $operator->id;
                $updates['cancelled_at'] = now();
            }

            $assignment->fill($updates)->save();

            if (array_key_exists('resources', $payload) && is_array($payload['resources'])) {
                $assignment->allocatedResources()->delete();

                foreach ($payload['resources'] as $resource) {
                    if (empty($resource['resource_type_id'])) {
                        continue;
                    }

                    TeamAssignmentAllocatedResource::query()->create([
                        'team_assignment_id' => $assignment->id,
                        'resource_type_id' => (int) $resource['resource_type_id'],
                        'quantity_allocated' => max(1, (int) ($resource['quantity_allocated'] ?? 1)),
                    ]);
                }
            }

            return $assignment->fresh(['team', 'allocatedResources.resourceType', 'notes.createdByOperator']);
        });
    }

    public function addNote(User $operator, TeamAssignment $assignment, string $note): TeamAssignment
    {
        $assignment->loadMissing('team', 'allocatedResources.resourceType', 'notes.createdByOperator');
        $incident = Incident::query()->findOrFail($assignment->incident_id);

        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot update this team assignment.');
        }

        $normalizedNote = trim($note);

        if ($normalizedNote === '') {
            throw new RuntimeException('Note is required.');
        }

        return DB::transaction(function () use ($operator, $assignment, $normalizedNote) {
            TeamAssignmentNote::query()->create([
                'team_assignment_id' => $assignment->id,
                'created_by_operator_id' => $operator->id,
                'note' => $normalizedNote,
            ]);

            return $assignment->fresh(['team', 'allocatedResources.resourceType', 'notes.createdByOperator']);
        });
    }

    public function delete(User $operator, TeamAssignment $assignment): void
    {
        $incident = Incident::query()->findOrFail($assignment->incident_id);

        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot delete this team assignment.');
        }

        if ($assignment->status !== TeamAssignmentStatus::Assigned->value) {
            throw new RuntimeException('This team assignment can only be deleted while still in assigned state.');
        }

        $assignment->delete();
    }
}
