<?php

namespace App\Http\Controllers\Api\Command;

use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 250), 1), 500);

        $incidents = Incident::query()
            ->with([
                'citizen',
                'caller',
                'teamAssignments.team',
                'incidentTypes',
            ])
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Incident $incident): array => $this->serializeIncident($incident))
            ->values();

        return response()->json([
            'items' => $incidents,
        ]);
    }

    private function serializeIncident(Incident $incident): array
    {
        $status = $incident->status?->value ?? (string) $incident->status;
        $latitude = $incident->latitude === null ? null : (float) $incident->latitude;
        $longitude = $incident->longitude === null ? null : (float) $incident->longitude;
        $location = $latitude !== null && $longitude !== null ? [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $incident->citizen_location_accuracy === null ? null : (float) $incident->citizen_location_accuracy,
            'altitude' => $incident->citizen_altitude === null ? null : (float) $incident->citizen_altitude,
            'altitude_accuracy' => $incident->citizen_altitude_accuracy === null ? null : (float) $incident->citizen_altitude_accuracy,
            'heading' => $incident->citizen_heading === null ? null : (float) $incident->citizen_heading,
            'heading_source' => $incident->citizen_heading_source,
            'captured_at' => $incident->citizen_location_captured_at?->toIso8601String(),
        ] : null;

        $citizen = $incident->citizen ?? $incident->caller;

        return [
            'id' => $incident->id,
            'display_id' => str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT),
            'citizen_id' => $incident->citizen_id,
            'caller_id' => $incident->caller_id,
            'actual_citizen_name' => $incident->actual_citizen_name,
            'actual_caller_name' => $incident->actual_caller_name,
            'citizen_name' => $incident->actual_citizen_name ?: ($citizen?->name ?? 'Unknown citizen'),
            'caller_name' => $incident->actual_caller_name ?: ($incident->caller?->name ?? 'Unknown caller'),
            'status' => $status,
            'status_label' => $this->formatLabel($status),
            'alert_level' => $incident->alert_level?->value ?? (string) $incident->alert_level,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location' => $incident->location,
            'location_label' => $this->locationLabel($incident),
            'citizen_location' => $location,
            'caller_location' => $location,
            'called_at' => $incident->called_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'created_at' => $incident->created_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
            'incident_types' => $incident->incidentTypes
                ->map(fn ($type): array => [
                    'id' => $type->id,
                    'name' => $type->name,
                ])
                ->values()
                ->all(),
            'team_assignments' => $incident->teamAssignments
                ->sortBy('assigned_at')
                ->map(fn ($assignment): array => [
                    'id' => $assignment->id,
                    'incident_id' => $assignment->incident_id,
                    'team_id' => $assignment->team_id,
                    'team' => $assignment->team ? [
                        'id' => $assignment->team->id,
                        'name' => $assignment->team->name,
                    ] : null,
                    'status' => $assignment->status,
                    'status_label' => $this->formatLabel((string) $assignment->status),
                    'contact_person' => $assignment->contact_person,
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                    'updated_at' => $assignment->updated_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function locationLabel(Incident $incident): string
    {
        $parts = collect([
            $incident->location_barangay,
            $incident->location_citymunicipality,
            $incident->location,
        ])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();

        return $parts->isNotEmpty() ? $parts->implode(', ') : 'Location unavailable';
    }

    private function formatLabel(string $value): string
    {
        return str($value)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->toString();
    }
}
