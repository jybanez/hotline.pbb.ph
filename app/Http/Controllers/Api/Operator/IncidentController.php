<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Teams\Models\ResourceType;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\TeamAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Support\Incidents\IncidentPayloadBuilder;
use App\Support\Incidents\IncidentTypeWorkbenchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentPayloadBuilder $incidentPayloads,
        private readonly IncidentTypeWorkbenchService $incidentTypes,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $statuses = collect(explode(',', (string) $request->query('status', 'Active,Deferred')))
            ->map(fn (string $status) => trim($status))
            ->filter()
            ->all();

        $incidents = Incident::query()
            ->with([
                'caller',
                'teamAssignments.team',
            ])
            ->where('operator_id', $request->user()->id)
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->latest('id')
            ->get()
            ->map(fn (Incident $incident) => $this->serializeIncidentSummary($incident))
            ->values()
            ->all();

        return response()->json([
            'items' => $incidents,
        ]);
    }

    public function show(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        return response()->json(
            $this->buildIncidentPayload($request, $incident),
        );
    }

    public function updateStatus(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:Deferred,Discarded,Resolved'],
        ]);

        $targetStatus = IncidentStatus::from($validated['status']);

        if ($targetStatus === IncidentStatus::Resolved) {
            $hasOpenAssignments = DB::table('team_assignments')
                ->where('incident_id', $incident->id)
                ->whereNotIn('status', [
                    TeamAssignmentStatus::Completed->value,
                    TeamAssignmentStatus::Cancelled->value,
                ])
                ->exists();

            if ($hasOpenAssignments) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Resolve is blocked until all team assignments are completed or cancelled.',
                ], 409);
            }
        }

        $incident->forceFill([
            'status' => $targetStatus,
            'resolved_at' => $targetStatus === IncidentStatus::Resolved ? now() : null,
        ])->save();

        return response()->json([
            'ok' => true,
            'incident' => $this->buildIncidentPayload($request, $incident->fresh()),
        ]);
    }

    public function updateActualCaller(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'actual_citizen_name' => ['required', 'string', 'max:255'],
            'actual_citizen_relationship' => ['nullable', 'string', 'max:255'],
            'actual_caller_name' => ['prohibited'],
            'actual_caller_relationship' => ['prohibited'],
        ]);

        $incident->forceFill($this->normalizedActualCallerUpdates($validated))->save();

        return response()->json([
            'ok' => true,
            'incident' => $this->buildIncidentPayload($request, $incident->fresh()),
        ]);
    }

    public function updateIntake(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'actual_citizen_name' => ['required', 'string', 'max:255'],
            'actual_citizen_relationship' => ['nullable', 'string', 'max:255'],
            'actual_caller_name' => ['prohibited'],
            'actual_caller_relationship' => ['prohibited'],
            ...$this->callerAddressValidationRules(),
        ]);

        $incident->forceFill([
            ...$this->normalizedActualCallerUpdates($validated),
            ...$this->normalizedCallerAddressUpdates($validated),
        ])->save();

        return response()->json([
            'ok' => true,
            'incident' => $this->buildIncidentPayload($request, $incident->fresh()),
        ]);
    }

    public function updateOtherDetails(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'other_details' => ['nullable', 'string'],
        ]);

        $incident->fill([
            'other_details' => $validated['other_details'] ?? null,
        ])->save();

        return response()->json([
            'ok' => true,
            'incident' => $this->buildIncidentPayload($request, $incident->fresh()),
        ]);
    }

    public function updateCallerAddress(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate($this->callerAddressValidationRules());

        $incident->forceFill($this->normalizedCallerAddressUpdates($validated))->save();

        return response()->json([
            'ok' => true,
            'incident' => $this->buildIncidentPayload($request, $incident->fresh()),
        ]);
    }

    public function updateCallerLocation(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'call_session_id' => ['nullable', 'integer'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'altitude' => ['nullable', 'numeric'],
            'altitude_accuracy' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'heading_source' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $capturedAt = isset($validated['captured_at'])
            ? Carbon::parse($validated['captured_at'])
            : now();
        $receivedAt = now();
        $callSessionId = isset($validated['call_session_id'])
            ? (int) $validated['call_session_id']
            : null;

        if ($callSessionId !== null) {
            $belongsToIncident = DB::table('call_sessions')
                ->where('id', $callSessionId)
                ->where('incident_id', $incident->id)
                ->exists();

            if (! $belongsToIncident) {
                $callSessionId = null;
            }
        }

        DB::table('incident_caller_locations')->insert([
            'incident_id' => $incident->id,
            'caller_id' => $incident->caller_id,
            'citizen_id' => $incident->citizen_id,
            'operator_id' => $incident->operator_id,
            'call_session_id' => $callSessionId,
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'accuracy' => isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
            'altitude' => isset($validated['altitude']) ? (float) $validated['altitude'] : null,
            'altitude_accuracy' => isset($validated['altitude_accuracy']) ? (float) $validated['altitude_accuracy'] : null,
            'heading' => isset($validated['heading']) ? (float) $validated['heading'] : null,
            'heading_source' => $validated['heading_source'] ?? null,
            'source' => $validated['source'] ?? 'operator-realtime',
            'captured_at' => $capturedAt,
            'received_at' => $receivedAt,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        if ($incident->caller_location_captured_at && $capturedAt->lt($incident->caller_location_captured_at)) {
            return response()->json([
                'ok' => true,
                'ignored' => true,
                'incident' => $this->buildIncidentPayload($request, $incident->fresh()),
            ]);
        }

        $incident->forceFill([
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'caller_location_accuracy' => isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
            'caller_altitude' => isset($validated['altitude']) ? (float) $validated['altitude'] : null,
            'caller_altitude_accuracy' => isset($validated['altitude_accuracy']) ? (float) $validated['altitude_accuracy'] : null,
            'caller_heading' => isset($validated['heading']) ? (float) $validated['heading'] : null,
            'caller_heading_source' => $validated['heading_source'] ?? null,
            'caller_location_captured_at' => $capturedAt,
        ])->save();

        return response()->json([
            'ok' => true,
            'incident' => $this->buildIncidentPayload($request, $incident->fresh()),
        ]);
    }

    public function callerLocations(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'since' => ['nullable', 'date'],
        ]);

        $limit = (int) ($validated['limit'] ?? 500);
        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;

        $locations = $incident->callerLocations()
            ->when($since, fn ($query) => $query->where('captured_at', '>=', $since))
            ->latest('captured_at')
            ->limit($limit)
            ->get()
            ->sortBy('captured_at')
            ->values()
            ->map(fn ($location) => [
                'id' => $location->id,
                'incident_id' => $location->incident_id,
                'call_session_id' => $location->call_session_id,
                'latitude' => $location->latitude === null ? null : (float) $location->latitude,
                'longitude' => $location->longitude === null ? null : (float) $location->longitude,
                'accuracy' => $location->accuracy === null ? null : (float) $location->accuracy,
                'altitude' => $location->altitude === null ? null : (float) $location->altitude,
                'altitude_accuracy' => $location->altitude_accuracy === null ? null : (float) $location->altitude_accuracy,
                'heading' => $location->heading === null ? null : (float) $location->heading,
                'heading_source' => $location->heading_source,
                'source' => $location->source,
                'captured_at' => $location->captured_at?->toIso8601String(),
                'received_at' => $location->received_at?->toIso8601String(),
            ]);

        return response()->json([
            'items' => $locations,
        ]);
    }

    public function updateIncidentTypeDetails(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'incident_types' => ['nullable', 'array'],
        ]);

        $items = $validated['items'] ?? $validated['incident_types'] ?? [];

        try {
            $incident = $this->incidentTypes->sync($request->user(), $incident, $items);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'incident' => $this->buildIncidentPayload($request, $incident),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIncidentPayload(Request $request, Incident $incident): array
    {
        return $this->incidentPayloads->buildWorkbenchPayload(
            $incident,
            $request->user(),
            includeLegacyAliases: $this->isLegacyOperatorAliasRoute($request),
        );
    }

    private function isLegacyOperatorAliasRoute(Request $request): bool
    {
        $path = $request->path();

        return str_contains($path, '/actual-caller')
            || str_contains($path, '/caller-address')
            || str_contains($path, '/caller-location');
    }

    public function attachIncidentType(Request $request, Incident $incident, IncidentType $incidentType): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        try {
            $attachedType = $this->incidentTypes->attach($request->user(), $incident, $incidentType);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'incident_type' => $this->incidentPayloads->serializeWorkbenchIncidentType($attachedType),
        ]);
    }

    public function removeIncidentType(Request $request, Incident $incident, IncidentType $incidentType): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        try {
            $this->incidentTypes->remove($request->user(), $incident, $incidentType);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'incident_type_id' => $incidentType->id,
        ]);
    }

    public function updateIncidentTypeDetail(Request $request, Incident $incident, IncidentType $incidentType): JsonResponse
    {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'field_id' => ['nullable', 'integer'],
            'field_key' => ['required', 'string', 'max:255'],
            'field_value' => ['nullable'],
        ]);

        try {
            $detail = $this->incidentTypes->saveDetail($request->user(), $incident, $incidentType, $validated);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'incident_type_id' => $incidentType->id,
            'field_id' => $validated['field_id'] ?? null,
            'field_key' => $validated['field_key'],
            'detail' => $detail ? $this->incidentPayloads->serializeWorkbenchIncidentTypeDetail($detail) : null,
        ]);
    }

    public function updateIncidentTypeResource(
        Request $request,
        Incident $incident,
        IncidentType $incidentType,
        ResourceType $resourceType,
    ): JsonResponse {
        abort_unless((int) $incident->operator_id === (int) $request->user()->id, 404);

        $validated = $request->validate([
            'quantity_needed' => ['nullable'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $resource = $this->incidentTypes->saveResource(
                $request->user(),
                $incident,
                $incidentType,
                $resourceType,
                $validated['quantity_needed'] ?? null,
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'incident_type_id' => $incidentType->id,
            'resource_type_id' => $resourceType->id,
            'resource' => $resource ? $this->incidentPayloads->serializeWorkbenchIncidentResourceNeeded($resource) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIncidentSummary(Incident $incident): array
    {
        $location = $incident->latitude !== null && $incident->longitude !== null ? [
            'latitude' => (float) $incident->latitude,
            'longitude' => (float) $incident->longitude,
            'accuracy' => $incident->caller_location_accuracy === null ? null : (float) $incident->caller_location_accuracy,
            'altitude' => $incident->caller_altitude === null ? null : (float) $incident->caller_altitude,
            'altitude_accuracy' => $incident->caller_altitude_accuracy === null ? null : (float) $incident->caller_altitude_accuracy,
            'heading' => $incident->caller_heading === null ? null : (float) $incident->caller_heading,
            'heading_source' => $incident->caller_heading_source,
            'captured_at' => $incident->caller_location_captured_at?->toIso8601String(),
        ] : null;

        return [
            'id' => $incident->id,
            'display_id' => str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT),
            'citizen_id' => $incident->caller_id,
            'caller_id' => $incident->caller_id,
            'citizen_avatar' => $incident->caller?->avatar,
            'caller_avatar' => $incident->caller?->avatar,
            'actual_citizen_name' => $incident->actual_caller_name,
            'actual_caller_name' => $incident->actual_caller_name,
            'status' => $incident->status->value,
            'latitude' => $incident->latitude,
            'longitude' => $incident->longitude,
            'citizen_location' => $location,
            'caller_location' => $location,
            'called_at' => $incident->called_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
            'team_assignments' => $incident->teamAssignments
                ->sortBy('assigned_at')
                ->map(fn ($assignment) => [
                    'id' => $assignment->id,
                    'incident_id' => $assignment->incident_id,
                    'team_id' => $assignment->team_id,
                    'team' => $assignment->team ? [
                        'id' => $assignment->team->id,
                        'name' => $assignment->team->name,
                    ] : null,
                    'status' => $assignment->status,
                    'contact_person' => $assignment->contact_person,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'updated_at' => $assignment->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, string|null>
     */
    private function normalizedActualCallerUpdates(array $validated): array
    {
        $relationship = $validated['actual_citizen_relationship'] ?? null;

        return [
            'actual_caller_name' => trim((string) $validated['actual_citizen_name']),
            'actual_caller_relationship' => is_string($relationship) ? trim($relationship) ?: null : null,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function callerAddressValidationRules(): array
    {
        return [
            'location' => ['nullable', 'string'],
            'location_road' => ['nullable', 'string', 'max:255'],
            'location_suburb' => ['nullable', 'string', 'max:255'],
            'location_barangay' => ['nullable', 'string', 'max:255'],
            'location_citymunicipality' => ['nullable', 'string', 'max:255'],
            'location_country' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizedCallerAddressUpdates(array $validated): array
    {
        return collect([
            'location' => $validated['location'] ?? null,
            'location_road' => $validated['location_road'] ?? null,
            'location_suburb' => $validated['location_suburb'] ?? null,
            'location_barangay' => $validated['location_barangay'] ?? null,
            'location_citymunicipality' => $validated['location_citymunicipality'] ?? null,
            'location_country' => $validated['location_country'] ?? null,
        ])
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();
    }
}
