<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Incidents\Models\IncidentTypeDefaultResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncidentTypeDefaultResourceController extends Controller
{
    public function store(Request $request, IncidentType $type): JsonResponse
    {
        $validated = $this->validatePayload($request, $type);
        $default = $type->defaultResources()->create($validated);

        return response()->json([
            'ok' => true,
            'default_resource' => $this->serializeDefault($default->fresh('resourceType.category')),
        ], 201);
    }

    public function update(Request $request, IncidentType $type, IncidentTypeDefaultResource $default): JsonResponse
    {
        abort_unless((int) $default->incident_type_id === (int) $type->id, 404);

        $validated = $this->validatePayload($request, $type, $default);
        $default->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'default_resource' => $this->serializeDefault($default->fresh('resourceType.category')),
        ]);
    }

    public function destroy(IncidentType $type, IncidentTypeDefaultResource $default): JsonResponse
    {
        abort_unless((int) $default->incident_type_id === (int) $type->id, 404);

        $default->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, IncidentType $type, ?IncidentTypeDefaultResource $default = null): array
    {
        $validated = $request->validate([
            'resource_type_id' => [
                'required',
                'integer',
                'exists:resource_types,id',
                Rule::unique('incident_type_default_resources', 'resource_type_id')
                    ->where(fn ($query) => $query->where('incident_type_id', $type->id))
                    ->ignore($default?->id),
            ],
            'quantity_required' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        return [
            'resource_type_id' => (int) $validated['resource_type_id'],
            'quantity_required' => (int) $validated['quantity_required'],
            'notes' => $validated['notes'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDefault(IncidentTypeDefaultResource $default): array
    {
        return [
            'id' => $default->id,
            'incident_type_id' => $default->incident_type_id,
            'resource_type_id' => $default->resource_type_id,
            'resource_name' => $default->resourceType?->name,
            'resource_category_name' => $default->resourceType?->category?->name,
            'quantity_required' => $default->quantity_required,
            'notes' => $default->notes,
            'sort_order' => $default->sort_order,
            'created_at' => $default->created_at?->toIso8601String(),
            'updated_at' => $default->updated_at?->toIso8601String(),
        ];
    }
}
