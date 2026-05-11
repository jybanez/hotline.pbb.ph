<?php

namespace App\Support\Admin;

use App\Domain\Incidents\Models\IncidentCategory;
use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Teams\Models\ResourceType;
use App\Domain\Teams\Models\ResourceTypeCategory;
use App\Domain\Teams\Models\Team;
use App\Domain\Teams\Models\TeamCategory;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BlockedDeleteInspectorService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function referencesForUser(User $user): array
    {
        $references = [];

        $map = [
            ['table' => 'incidents', 'column' => 'citizen_id', 'label' => 'Incidents as citizen'],
            ['table' => 'incidents', 'column' => 'operator_id', 'label' => 'Incidents as operator'],
            ['table' => 'call_attempts', 'column' => 'citizen_id', 'label' => 'Call attempts as citizen'],
            ['table' => 'call_attempts', 'column' => 'answered_by_operator_id', 'label' => 'Answered call attempts'],
            ['table' => 'call_attempt_operator_attempts', 'column' => 'operator_id', 'label' => 'Operator ring attempts'],
            ['table' => 'call_sessions', 'column' => 'citizen_id', 'label' => 'Call sessions as citizen'],
            ['table' => 'call_participants', 'column' => 'user_id', 'label' => 'Call session participants'],
            ['table' => 'incident_messages', 'column' => 'sender_id', 'label' => 'Incident messages'],
            ['table' => 'message_attachments', 'column' => 'uploaded_by', 'label' => 'Uploaded attachments'],
            ['table' => 'media', 'column' => 'peer_user_id', 'label' => 'Media peer artifacts'],
            ['table' => 'team_assignments', 'column' => 'assigned_by_operator_id', 'label' => 'Team assignments created'],
            ['table' => 'team_assignments', 'column' => 'cancelled_by_operator_id', 'label' => 'Team assignments cancelled'],
            ['table' => 'incident_transfers', 'column' => 'from_operator_id', 'label' => 'Transfers from operator'],
            ['table' => 'incident_transfers', 'column' => 'to_operator_id', 'label' => 'Transfers to operator'],
            ['table' => 'activity_logs', 'column' => 'actor_id', 'label' => 'Activity log actor records'],
        ];

        foreach ($map as $entry) {
            if (! Schema::hasColumn($entry['table'], $entry['column'])) {
                continue;
            }

            $count = DB::table($entry['table'])
                ->where($entry['column'], $user->id)
                ->count();

            if ($count > 0) {
                $references[] = [
                    'table' => $entry['table'],
                    'column' => $entry['column'],
                    'label' => $entry['label'],
                    'count' => $count,
                ];
            }
        }

        return $references;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function referencesForResourceType(ResourceType $resourceType): array
    {
        $references = [];

        $map = [
            ['table' => 'incident_type_default_resources', 'column' => 'resource_type_id', 'label' => 'Incident type default resources'],
            ['table' => 'incident_resources_needed', 'column' => 'resource_type_id', 'label' => 'Incident needed resources'],
            ['table' => 'team_resource_inventories', 'column' => 'resource_type_id', 'label' => 'Team resource inventories'],
            ['table' => 'team_assignment_allocated_resources', 'column' => 'resource_type_id', 'label' => 'Allocated team-assignment resources'],
        ];

        foreach ($map as $entry) {
            $count = DB::table($entry['table'])
                ->where($entry['column'], $resourceType->id)
                ->count();

            if ($count > 0) {
                $references[] = [
                    'table' => $entry['table'],
                    'column' => $entry['column'],
                    'label' => $entry['label'],
                    'count' => $count,
                ];
            }
        }

        return $references;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function referencesForResourceTypeCategory(ResourceTypeCategory $category): array
    {
        return $this->referencesForSimpleOwner('resource_types', 'category_id', $category->id, 'Resource types in category');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function referencesForIncidentCategory(IncidentCategory $category): array
    {
        return $this->referencesForSimpleOwner('incident_types', 'incident_category_id', $category->id, 'Incident types in category');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function referencesForIncidentType(IncidentType $type): array
    {
        return array_values(array_filter([
            ...$this->referencesForSimpleOwner('incident_type_fields', 'incident_type_id', $type->id, 'Incident type fields'),
            ...$this->referencesForSimpleOwner('incident_type_default_resources', 'incident_type_id', $type->id, 'Incident type default resources'),
            ...$this->referencesForSimpleOwner('incident_type_details', 'incident_type_id', $type->id, 'Incident type details'),
            ...$this->referencesForSimpleOwner('incident_resources_needed', 'incident_type_id', $type->id, 'Incident resources needed'),
        ]));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function referencesForTeamCategory(TeamCategory $category): array
    {
        return $this->referencesForSimpleOwner('teams', 'team_category_id', $category->id, 'Teams in category');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function referencesForTeam(Team $team): array
    {
        return array_values(array_filter([
            ...$this->referencesForSimpleOwner('team_resource_inventories', 'team_id', $team->id, 'Team resource inventories'),
            ...$this->referencesForSimpleOwner('team_assignments', 'team_id', $team->id, 'Team assignments'),
        ]));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function referencesForSimpleOwner(string $table, string $column, int|string $value, string $label): array
    {
        if (!Schema::hasColumn($table, $column)) {
            return [];
        }

        $count = DB::table($table)
            ->where($column, $value)
            ->count();

        if ($count === 0) {
            return [];
        }

        return [[
            'table' => $table,
            'column' => $column,
            'label' => $label,
            'count' => $count,
        ]];
    }
}
