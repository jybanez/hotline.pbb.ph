<?php

namespace App\Support\IncidentRelay;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Media\Models\Media;
use App\Domain\Messages\Models\MessageAttachment;
use App\Support\Settings\SettingsService;
use Illuminate\Support\Str;

class IncidentRelaySerializer
{
    public const MESSAGE_TYPE = 'hotline.incident.upserted';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly IncidentRelayHubContext $hubContext,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Incident $incident): array
    {
        $incident->loadMissing([
            'callSessions',
            'incidentTypes.category',
            'incidentTypeDetails.incidentType.category',
            'incidentResourcesNeeded.resourceType.category',
            'teamAssignments.team.category',
            'mediaItems',
            'messages.attachments',
        ]);

        $snapshot = $this->hubContext->snapshot();
        $source = $this->hubContext->source($snapshot);
        $sourceSystem = $this->sourceSystem();
        $sourceHubId = $source['hub_id'] ?? 'unknown-hub';
        $incidentId = (string) $incident->id;
        $stableKey = implode(':', [$sourceHubId, $sourceSystem, $incidentId]);
        $revision = $this->revision($incident);

        return [
            'schema_version' => 1,
            'message_type' => self::MESSAGE_TYPE,
            'stable_incident_key' => $stableKey,
            'message_idempotency_key' => $stableKey.':'.$revision,
            'revision' => $revision,
            'source' => array_merge($source, [
                'system' => $sourceSystem,
                'incident_id' => $incidentId,
                'incident_ref' => $this->incidentRef($incident),
            ]),
            'incident' => [
                'id' => $incident->id,
                'ref' => $this->incidentRef($incident),
                'status' => $this->enumValue($incident->status),
                'alert_level' => $this->enumValue($incident->alert_level),
                'location' => $this->location($incident),
                'timestamps' => $this->timestamps($incident),
                'types' => $this->incidentTypes($incident),
                'details' => $this->details($incident),
                'resources' => $this->resources($incident),
                'team_assignments' => $this->teamAssignments($incident),
                'media_refs' => $this->mediaRefs($incident, $sourceHubId),
            ],
            'debug_context' => $this->debugContext($incident),
            'notes' => [
                'revision_source' => 'updated_at',
                'revision_limitation' => 'V1 uses incident updated_at as the revision. Changes that do not touch the incident timestamp must be requeued by the worker or future model hooks.',
                'media_access' => 'media_refs contain metadata only; upstream retrieval must use trusted media access flow.',
            ],
        ];
    }

    public function sourceSystem(): string
    {
        $sourceSystem = trim((string) $this->settings->get('incident_relay_source_system', 'hotline.incident'));

        return $sourceSystem !== '' ? $sourceSystem : 'hotline.incident';
    }

    private function revision(Incident $incident): string
    {
        return ($incident->updated_at ?? $incident->created_at ?? now())->toIso8601String();
    }

    private function incidentRef(Incident $incident): string
    {
        return sprintf('%06d', (int) $incident->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function location(Incident $incident): array
    {
        return [
            'label' => $this->stringOrNull($incident->location),
            'lat' => $incident->latitude !== null ? round((float) $incident->latitude, 5) : null,
            'lng' => $incident->longitude !== null ? round((float) $incident->longitude, 5) : null,
            'road' => $this->stringOrNull($incident->location_road),
            'suburb' => $this->stringOrNull($incident->location_suburb),
            'barangay' => $this->stringOrNull($incident->location_barangay),
            'city_municipality' => $this->stringOrNull($incident->location_citymunicipality),
            'country' => $this->stringOrNull($incident->location_country),
            'captured_at' => $incident->citizen_location_captured_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function timestamps(Incident $incident): array
    {
        $firstCall = $incident->callSessions->sortBy('started_at')->first();

        return [
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
            'reported_at' => $incident->called_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'call_started_at' => $firstCall?->started_at?->toIso8601String(),
            'call_answered_at' => $firstCall?->answered_at?->toIso8601String(),
            'call_ended_at' => $firstCall?->ended_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function incidentTypes(Incident $incident): array
    {
        return $incident->incidentTypes
            ->map(fn ($type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'category_id' => $type->category?->id,
                'category_name' => $type->category?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function details(Incident $incident): array
    {
        return $incident->incidentTypeDetails
            ->map(fn ($detail): array => [
                'incident_type_id' => $detail->incident_type_id,
                'incident_type_name' => $detail->incidentType?->name,
                'field_id' => $detail->field_id,
                'field_key' => $detail->field_key,
                'field_label' => $detail->field_label,
                'value' => $this->tonedDownValue($detail->field_value),
                'input_type' => $detail->input_type,
                'unit' => $detail->unit,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resources(Incident $incident): array
    {
        return $incident->incidentResourcesNeeded
            ->map(function ($resource): array {
                $type = $resource->resourceType;

                return [
                    'resource_type_id' => $resource->resource_type_id,
                    'resource_type_name' => $type?->name,
                    'resource_type_category_id' => $type?->category?->id,
                    'resource_type_category_name' => $type?->category?->name,
                    'quantity' => (int) $resource->quantity_required,
                    'unit_label' => $type?->unit_label ?? 'units',
                    'notes' => $this->tonedDownValue($resource->notes),
                    'incident_type_id' => $resource->incident_type_id,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamAssignments(Incident $incident): array
    {
        return $incident->teamAssignments
            ->map(fn ($assignment): array => [
                'assignment_id' => $assignment->id,
                'team_id' => $assignment->team_id,
                'team_name' => $assignment->team?->name,
                'team_category' => $assignment->team?->category?->name,
                'status' => $assignment->status,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'accepted_at' => $assignment->accepted_at?->toIso8601String(),
                'enroute_at' => $assignment->enroute_at?->toIso8601String(),
                'arrived_at' => $assignment->arrived_at?->toIso8601String(),
                'completed_at' => $assignment->completed_at?->toIso8601String(),
                'cancelled_at' => $assignment->cancelled_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mediaRefs(Incident $incident, string $sourceHubId): array
    {
        $incidentMedia = $incident->mediaItems
            ->map(fn (Media $media): array => [
                'kind' => 'incident_media',
                'source_hub_id' => $sourceHubId,
                'incident_id' => $incident->id,
                'incident_ref' => $this->incidentRef($incident),
                'media_id' => $media->id,
                'type' => $media->type,
                'mime_type' => $this->stringOrNull($media->metadata_json['mime_type'] ?? null),
                'original_filename' => $this->safeFilename($media->metadata_json['original_filename'] ?? null),
                'created_at' => $media->created_at?->toIso8601String(),
                'available_at' => $media->available_at?->toIso8601String(),
                'peer_role' => $this->stringOrNull($media->peer_role),
            ]);

        $attachments = $incident->messages
            ->flatMap(fn ($message) => $message->attachments->map(
                fn (MessageAttachment $attachment): array => [
                    'kind' => 'message_attachment',
                    'source_hub_id' => $sourceHubId,
                    'incident_id' => $incident->id,
                    'incident_ref' => $this->incidentRef($incident),
                    'attachment_id' => $attachment->id,
                    'message_id' => $message->id,
                    'type' => $attachment->type,
                    'mime_type' => $attachment->mime_type,
                    'original_filename' => $this->safeFilename($attachment->original_filename),
                    'created_at' => $attachment->created_at?->toIso8601String(),
                    'uploader_role' => $this->stringOrNull($message->sender_role),
                ],
            ));

        return $incidentMedia->concat($attachments)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function debugContext(Incident $incident): array
    {
        return [
            'retention' => 'short',
            'incident_id' => $incident->id,
            'status' => $this->enumValue($incident->status),
            'call_session_ids' => $incident->callSessions->pluck('id')->values()->all(),
            'incident_type_ids' => $incident->incidentTypes->pluck('id')->values()->all(),
            'team_assignment_ids' => $incident->teamAssignments->pluck('id')->values()->all(),
            'resource_need_ids' => $incident->incidentResourcesNeeded->pluck('id')->values()->all(),
            'media_count' => $incident->mediaItems->count(),
            'message_attachment_count' => $incident->messages->sum(fn ($message): int => $message->attachments->count()),
        ];
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function tonedDownValue(mixed $value): mixed
    {
        if (is_scalar($value)) {
            return Str::limit(trim((string) $value), 500);
        }

        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): mixed => is_scalar($item) ? Str::limit(trim((string) $item), 250) : null)
                ->filter(fn (mixed $item): bool => $item !== null && $item !== '')
                ->values()
                ->all();
        }

        return null;
    }

    private function safeFilename(mixed $value): ?string
    {
        $filename = $this->stringOrNull($value);

        if ($filename === null) {
            return null;
        }

        $filename = basename(str_replace('\\', '/', $filename));

        return Str::limit($filename, 160);
    }
}
