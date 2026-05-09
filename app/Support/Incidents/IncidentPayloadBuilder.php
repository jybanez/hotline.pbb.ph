<?php

namespace App\Support\Incidents;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentCategory;
use App\Domain\Incidents\Models\IncidentResourceNeeded;
use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Incidents\Models\IncidentTypeDetail;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Teams\Models\ResourceType;
use App\Domain\Teams\Models\Team;
use App\Domain\Teams\Models\TeamCategory;
use App\Domain\Users\Models\User;
use Illuminate\Support\Collection;

class IncidentPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function serializeWorkbenchIncidentType(IncidentType $type): array
    {
        $type->loadMissing(
            'category',
            'fields',
            'defaultResources.resourceType.category',
        );

        return [
            'id' => $type->id,
            'incident_type_id' => $type->id,
            'category_id' => $type->incident_category_id,
            'category_name' => $type->category?->name,
            'category' => $type->category ? [
                'id' => $type->category->id,
                'name' => $type->category->name,
                'description' => $type->category->description,
                'sort_order' => $type->category->sort_order,
            ] : null,
            'name' => $type->name,
            'description' => $type->description,
            'pivot' => [
                'id' => $type->pivot?->id,
            ],
            'fields' => $type->fields
                ->map(fn ($field) => $this->serializeWorkbenchIncidentTypeField($field))
                ->values()
                ->all(),
            'resource_defaults' => $type->defaultResources
                ->map(fn ($default) => [
                    'id' => $default->id,
                    'incident_type_id' => $default->incident_type_id,
                    'resource_type_id' => $default->resource_type_id,
                    'sort_order' => $default->sort_order,
                    'resource_type' => $default->resourceType ? [
                        'id' => $default->resourceType->id,
                        'category_id' => $default->resourceType->category_id,
                        'category_name' => $default->resourceType->category?->name,
                        'name' => $default->resourceType->name,
                        'unit_label' => $default->resourceType->unit_label,
                    ] : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWorkbenchIncidentTypeField(object $field): array
    {
        $config = $field->config_json ?? [];

        return [
            'id' => $field->id,
            'incident_type_id' => $field->incident_type_id,
            'field_key' => $field->field_key,
            'field_label' => $field->field_label,
            'input_type' => $field->input_type,
            'options' => $field->options_json ?? [],
            'config' => $config,
            'preset' => $config['preset'] ?? null,
            'preset_label' => $config['preset_label'] ?? null,
            'repeatable' => (bool) ($config['repeatable'] ?? false),
            'fields' => $config['fields'] ?? [],
            'default_value' => $field->default_value,
            'placeholder' => $field->placeholder,
            'unit' => $field->unit,
            'is_required' => (bool) $field->is_required,
            'sort_order' => $field->sort_order,
            'min' => $field->min,
            'max' => $field->max,
            'step' => $field->step,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeWorkbenchIncidentTypeDetail(IncidentTypeDetail $detail): array
    {
        $config = $detail->config_json ?? [];

        return [
            'id' => $detail->id,
            'incident_id' => $detail->incident_id,
            'incident_type_id' => $detail->incident_type_id,
            'field_id' => $detail->field_id,
            'field_label' => $detail->field_label,
            'field_key' => $detail->field_key,
            'field_value' => $detail->field_value,
            'input_type' => $detail->input_type,
            'options' => $detail->options_json ?? [],
            'config' => $config,
            'preset' => $config['preset'] ?? null,
            'preset_label' => $config['preset_label'] ?? null,
            'repeatable' => (bool) ($config['repeatable'] ?? false),
            'fields' => $config['fields'] ?? [],
            'unit' => $detail->unit,
            'placeholder' => $detail->placeholder,
            'is_required' => (bool) $detail->is_required,
            'sort_order' => $detail->sort_order,
            'created_at' => $detail->created_at?->toIso8601String(),
            'updated_at' => $detail->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeWorkbenchIncidentResourceNeeded(IncidentResourceNeeded $resource): array
    {
        $resource->loadMissing('resourceType.category');

        return [
            'id' => $resource->id,
            'incident_id' => $resource->incident_id,
            'incident_type_id' => $resource->incident_type_id,
            'resource_type_id' => $resource->resource_type_id,
            'resource_type' => $resource->resourceType ? [
                'id' => $resource->resourceType->id,
                'category_id' => $resource->resourceType->category_id,
                'category_name' => $resource->resourceType->category?->name,
                'name' => $resource->resourceType->name,
                'unit_label' => $resource->resourceType->unit_label,
            ] : null,
            'resource_name' => $resource->resourceType?->name,
            'quantity_needed' => $resource->quantity_required,
            'quantity_required' => $resource->quantity_required,
            'notes' => $resource->notes,
            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildWorkbenchPayload(Incident $incident, ?User $viewer = null): array
    {
        $incident->loadMissing(
            'caller',
            'operator',
            'callSessions.participants',
            'transfers.fromOperator',
            'transfers.toOperator',
            'messages.sender',
            'messages.attachments',
            'mediaItems',
            'incidentTypes.category',
            'incidentTypes.fields',
            'incidentTypes.defaultResources.resourceType.category',
            'incidentTypeDetails',
            'incidentResourcesNeeded.resourceType.category',
            'teamAssignments.team',
            'teamAssignments.team.category',
            'teamAssignments.assignedByOperator',
            'teamAssignments.cancelledByOperator',
            'teamAssignments.allocatedResources.resourceType',
            'teamAssignments.notes.createdByOperator',
        );

        $isCallerViewer = $viewer?->role === UserRole::Caller;
        $sortedCallSessions = $incident->callSessions
            ->sortBy('created_at')
            ->values();
        $currentCallSession = $sortedCallSessions->last();

        return [
            'id' => $incident->id,
            'display_id' => str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT),
            'caller_id' => $incident->caller_id,
            'caller' => $incident->caller ? [
                'id' => $incident->caller->id,
                'name' => $incident->caller->name,
                'avatar' => $incident->caller->avatar,
                'mobile' => $incident->caller->mobile,
            ] : null,
            'actual_caller_name' => $incident->actual_caller_name,
            'actual_caller_relationship' => $incident->actual_caller_relationship,
            'latitude' => $incident->latitude,
            'longitude' => $incident->longitude,
            'caller_location' => $this->serializeCallerLocation($incident),
            'location' => $incident->location,
            'location_road' => $incident->location_road,
            'location_suburb' => $incident->location_suburb,
            'location_barangay' => $incident->location_barangay,
            'location_citymunicipality' => $incident->location_citymunicipality,
            'location_country' => $incident->location_country,
            'operator_id' => $incident->operator_id,
            'operator' => $incident->operator ? [
                'id' => $incident->operator->id,
                'name' => $incident->operator->name,
                'level' => null,
                'avatar' => $incident->operator->avatar,
            ] : null,
            'status' => $incident->status->value,
            'alert_level' => $incident->alert_level->value,
            'called_at' => $incident->called_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'other_details' => $incident->other_details ?? '',
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
            'current_call_session' => $currentCallSession ? $this->serializeCallSession($currentCallSession) : null,
            'call_history' => $sortedCallSessions
                ->map(fn ($session) => $this->serializeCallSession($session))
                ->values()
                ->all(),
            'transfer_history' => $incident->transfers
                ->sortBy('requested_at')
                ->map(fn ($transfer) => [
                    'id' => $transfer->id,
                    'incident_id' => $transfer->incident_id,
                    'from_operator_id' => $transfer->from_operator_id,
                    'from_operator' => $transfer->fromOperator ? [
                        'id' => $transfer->fromOperator->id,
                        'name' => $transfer->fromOperator->name,
                        'avatar' => $transfer->fromOperator->avatar,
                    ] : null,
                    'to_operator_id' => $transfer->to_operator_id,
                    'to_operator' => $transfer->toOperator ? [
                        'id' => $transfer->toOperator->id,
                        'name' => $transfer->toOperator->name,
                        'avatar' => $transfer->toOperator->avatar,
                    ] : null,
                    'reason' => $transfer->reason,
                    'status' => $transfer->status,
                    'requested_at' => $transfer->requested_at?->toIso8601String(),
                    'accepted_at' => $transfer->accepted_at?->toIso8601String(),
                    'rejected_at' => $transfer->rejected_at?->toIso8601String(),
                    'cancelled_at' => $transfer->cancelled_at?->toIso8601String(),
                    'completed_at' => $transfer->completed_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'messages' => $incident->messages
                ->sortBy('created_at')
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'incident_id' => $message->incident_id,
                    'sender_id' => $message->sender_id,
                    'sender_role' => $message->sender_role,
                    'sender_name' => $message->sender?->name,
                    'sender_avatar' => $message->sender?->avatar,
                    'body' => $message->body,
                    'type' => $message->type,
                    'attachments' => $message->attachments
                        ->sortBy('created_at')
                        ->map(fn ($attachment) => [
                            'id' => $attachment->id,
                            'type' => $attachment->type,
                            'mime_type' => $attachment->mime_type,
                            'original_filename' => $attachment->original_filename,
                            'stored_path' => $attachment->stored_path,
                            'file_size' => $attachment->file_size,
                            'thumbnail_path' => $attachment->thumbnail_path,
                            'uploaded_by' => $attachment->uploaded_by,
                            'created_at' => $attachment->created_at?->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'media' => $incident->mediaItems
                ->filter(fn ($media) => ! $isCallerViewer || $media->type === 'caller_video')
                ->sortBy('created_at')
                ->map(fn ($media) => [
                    'id' => $media->id,
                    'incident_id' => $media->incident_id,
                    'call_session_id' => $media->call_session_id,
                    'type' => $media->type,
                    'peer_user_id' => $media->peer_user_id,
                    'peer_role' => $media->peer_role,
                    'peer_label' => $media->peer_label,
                    'path' => $media->available_at ? $media->path : null,
                    'duration_seconds' => $media->duration_seconds,
                    'metadata' => $media->metadata_json ?? [],
                    'processing' => $media->available_at === null,
                    'created_at' => $media->created_at?->toIso8601String(),
                    'available_at' => $media->available_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'incident_types' => $incident->incidentTypes
                ->sortBy(fn (IncidentType $type) => sprintf(
                    '%08d:%s:%s',
                    (int) ($type->category?->sort_order ?? PHP_INT_MAX),
                    strtolower((string) ($type->category?->name ?? '')),
                    strtolower((string) $type->name),
                ))
                ->map(fn (IncidentType $type) => $this->serializeWorkbenchIncidentType($type))
                ->values()
                ->all(),
            'incident_type_details' => $incident->incidentTypeDetails
                ->map(fn (IncidentTypeDetail $detail) => $this->serializeWorkbenchIncidentTypeDetail($detail))
                ->values()
                ->all(),
            'incident_resources_needed' => $incident->incidentResourcesNeeded
                ->map(fn (IncidentResourceNeeded $resource) => $this->serializeWorkbenchIncidentResourceNeeded($resource))
                ->values()
                ->all(),
            'team_assignments' => $incident->teamAssignments
                ->sortBy('assigned_at')
                ->map(fn ($assignment) => [
                    'id' => $assignment->id,
                    'incident_id' => $assignment->incident_id,
                    'team_id' => $assignment->team_id,
                    'team' => $assignment->team ? [
                        'id' => $assignment->team->id,
                        'name' => $assignment->team->name,
                        'category' => $assignment->team->category ? [
                            'id' => $assignment->team->category->id,
                            'name' => $assignment->team->category->name,
                        ] : null,
                    ] : null,
                    'assigned_by_operator_id' => $assignment->assigned_by_operator_id,
                    'assigned_by_operator' => $assignment->assignedByOperator ? [
                        'id' => $assignment->assignedByOperator->id,
                        'name' => $assignment->assignedByOperator->name,
                    ] : null,
                    'status' => $assignment->status,
                    'contact_person' => $assignment->contact_person,
                    'cancelled_from_status' => $assignment->cancelled_from_status,
                    'cancel_reason_code' => $assignment->cancel_reason_code,
                    'cancel_reason_note' => $assignment->cancel_reason_note,
                    'cancelled_by_operator_id' => $assignment->cancelled_by_operator_id,
                    'cancelled_by_operator' => $assignment->cancelledByOperator ? [
                        'id' => $assignment->cancelledByOperator->id,
                        'name' => $assignment->cancelledByOperator->name,
                    ] : null,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'accepted_at' => $assignment->accepted_at?->toIso8601String(),
                    'enroute_at' => $assignment->enroute_at?->toIso8601String(),
                    'arrived_at' => $assignment->arrived_at?->toIso8601String(),
                    'completed_at' => $assignment->completed_at?->toIso8601String(),
                    'cancelled_at' => $assignment->cancelled_at?->toIso8601String(),
                    'notes' => $assignment->notes
                        ->map(fn ($note) => [
                            'id' => $note->id,
                            'team_assignment_id' => $note->team_assignment_id,
                            'created_by_operator_id' => $note->created_by_operator_id,
                            'created_by_operator' => $note->createdByOperator ? [
                                'id' => $note->createdByOperator->id,
                                'name' => $note->createdByOperator->name,
                            ] : null,
                            'note' => $note->note,
                            'created_at' => $note->created_at?->toIso8601String(),
                            'updated_at' => $note->updated_at?->toIso8601String(),
                        ])
                        ->values()
                        ->all(),
                    'allocated_resources' => $assignment->allocatedResources
                        ->map(fn ($resource) => [
                            'id' => $resource->id,
                            'resource_type_id' => $resource->resource_type_id,
                            'resource_type' => $resource->resourceType ? [
                                'id' => $resource->resourceType->id,
                                'category_id' => $resource->resourceType->category_id,
                                'category_name' => $resource->resourceType->category?->name,
                                'name' => $resource->resourceType->name,
                                'unit_label' => $resource->resourceType->unit_label,
                            ] : null,
                            'quantity_allocated' => $resource->quantity_allocated,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function serializeCallerLocation(Incident $incident): ?array
    {
        if ($incident->latitude === null || $incident->longitude === null) {
            return null;
        }

        return [
            'latitude' => (float) $incident->latitude,
            'longitude' => (float) $incident->longitude,
            'accuracy' => $incident->caller_location_accuracy === null ? null : (float) $incident->caller_location_accuracy,
            'altitude' => $incident->caller_altitude === null ? null : (float) $incident->caller_altitude,
            'altitude_accuracy' => $incident->caller_altitude_accuracy === null ? null : (float) $incident->caller_altitude_accuracy,
            'heading' => $incident->caller_heading === null ? null : (float) $incident->caller_heading,
            'heading_source' => $incident->caller_heading_source,
            'captured_at' => $incident->caller_location_captured_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function buildWorkbenchLookups(): array
    {
        return [
            'incident_type_categories' => $this->serializeIncidentTypeCategories(),
            'incident_type_catalog' => $this->serializeIncidentTypeCatalog(),
            'resource_types' => $this->serializeResourceTypes(),
            'team_categories' => $this->serializeTeamCategories(),
            'teams' => $this->serializeTeams(),
        ];
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return array<int, array<string, mixed>>
     */
    public function buildHistoryList(Collection $incidents): array
    {
        return $incidents
            ->map(fn (Incident $incident) => [
                'id' => $incident->id,
                'display_id' => str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT),
                'status' => $incident->status->value,
                'created_at' => $incident->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCallSession(object $session): array
    {
        return [
            'id' => $session->id,
            'incident_id' => $session->incident_id,
            'caller_id' => $session->caller_id,
            'status' => $session->status->value,
            'outcome' => $session->outcome?->value,
            'started_at' => $session->started_at?->toIso8601String(),
            'answered_at' => $session->answered_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
            'participants' => $session->participants->map(fn ($participant) => [
                'id' => $participant->id,
                'call_session_id' => $participant->call_session_id,
                'user_id' => $participant->user_id,
                'participant_role' => $participant->participant_role,
                'joined_at' => $participant->joined_at?->toIso8601String(),
                'left_at' => $participant->left_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeIncidentTypeCategories(): array
    {
        return IncidentCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (IncidentCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeIncidentTypeCatalog(): array
    {
        return IncidentType::query()
            ->with(['category', 'fields', 'defaultResources.resourceType.category'])
            ->join('incident_categories', 'incident_types.incident_category_id', '=', 'incident_categories.id')
            ->orderBy('incident_categories.sort_order')
            ->orderBy('incident_categories.name')
            ->orderBy('incident_types.name')
            ->select('incident_types.*')
            ->get()
            ->map(fn (IncidentType $type): array => $this->serializeWorkbenchIncidentType($type))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeResourceTypes(): array
    {
        return ResourceType::query()
            ->with('category')
            ->join('resource_type_categories', 'resource_types.category_id', '=', 'resource_type_categories.id')
            ->orderBy('resource_type_categories.sort_order')
            ->orderBy('resource_type_categories.name')
            ->orderBy('resource_types.name')
            ->select('resource_types.*')
            ->get()
            ->map(fn (ResourceType $resourceType): array => [
                'id' => $resourceType->id,
                'category_id' => $resourceType->category_id,
                'category_name' => $resourceType->category?->name,
                'name' => $resourceType->name,
                'unit_label' => $resourceType->unit_label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeTeamCategories(): array
    {
        return TeamCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TeamCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeTeams(): array
    {
        return Team::query()
            ->with('category')
            ->join('team_categories', 'teams.team_category_id', '=', 'team_categories.id')
            ->orderBy('team_categories.sort_order')
            ->orderBy('team_categories.name')
            ->orderBy('teams.name')
            ->select('teams.*')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'team_category_id' => $team->team_category_id,
                'category_name' => $team->category?->name,
                'category' => $team->category ? [
                    'id' => $team->category->id,
                    'name' => $team->category->name,
                    'description' => $team->category->description,
                    'sort_order' => $team->category->sort_order,
                ] : null,
                'name' => $team->name,
                'status' => $team->status,
            ])
            ->values()
            ->all();
    }
}
