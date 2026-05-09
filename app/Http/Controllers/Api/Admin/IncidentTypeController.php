<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Incidents\Models\IncidentTypeDefaultResource;
use App\Domain\Incidents\Models\IncidentTypeField;
use App\Domain\Incidents\Models\IncidentType;
use App\Domain\Teams\Models\ResourceType;
use App\Http\Controllers\Controller;
use App\Support\Admin\BlockedDeleteInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentTypeController extends Controller
{
    public function __construct(
        private readonly BlockedDeleteInspectorService $blockedDeletes,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = IncidentType::query()
            ->select('incident_types.*')
            ->with('category')
            ->withCount(['fields', 'defaultResources'])
            ->join('incident_categories', 'incident_types.incident_category_id', '=', 'incident_categories.id')
            ->orderBy('incident_categories.sort_order')
            ->orderBy('incident_categories.name')
            ->orderBy('incident_types.name')
            ->get()
            ->map(fn (IncidentType $type) => $this->serializeIncidentType($type))
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $type = IncidentType::query()->create($validated);

        return response()->json([
            'ok' => true,
            'type' => $this->serializeIncidentType($type->fresh('category')),
        ], 201);
    }

    public function show(IncidentType $type): JsonResponse
    {
        $type->load([
            'category',
            'fields',
            'defaultResources.resourceType.category',
        ]);

        $resourceTypeOptions = ResourceType::query()
            ->with('category')
            ->join('resource_type_categories', 'resource_types.category_id', '=', 'resource_type_categories.id')
            ->orderBy('resource_type_categories.sort_order')
            ->orderBy('resource_type_categories.name')
            ->orderBy('resource_types.name')
            ->select('resource_types.*')
            ->get()
            ->map(fn (ResourceType $resourceType) => [
                'id' => $resourceType->id,
                'category_name' => $resourceType->category?->name,
                'name' => $resourceType->name,
                'label' => trim(sprintf('%s / %s', $resourceType->category?->name ?? 'Category', $resourceType->name)),
            ])
            ->values()
            ->all();

        return response()->json([
            'type' => $this->serializeIncidentType($type),
            'fields' => $type->fields->map(fn (IncidentTypeField $field) => $this->serializeField($field))->values()->all(),
            'default_required_resources' => $type->defaultResources
                ->map(fn (IncidentTypeDefaultResource $default) => $this->serializeDefaultResource($default))
                ->values()
                ->all(),
            'resource_type_options' => $resourceTypeOptions,
        ]);
    }

    public function update(Request $request, IncidentType $type): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $type->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'type' => $this->serializeIncidentType($type->fresh('category')),
        ]);
    }

    public function destroy(IncidentType $type): JsonResponse
    {
        $references = $this->blockedDeletes->referencesForIncidentType($type);

        if ($references !== []) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$type->name}.",
                'references' => $references,
            ], 409);
        }

        $type->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array{incident_category_id: int, name: string, description: string|null}
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'incident_category_id' => ['required', 'integer', 'exists:incident_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIncidentType(IncidentType $type): array
    {
        return [
            'id' => $type->id,
            'incident_category_id' => $type->incident_category_id,
            'category_name' => $type->category?->name,
            'name' => $type->name,
            'description' => $type->description,
            'fields_count' => (int) ($type->fields_count ?? ($type->relationLoaded('fields') ? $type->fields->count() : 0)),
            'default_required_resources_count' => (int) ($type->default_resources_count ?? ($type->relationLoaded('defaultResources') ? $type->defaultResources->count() : 0)),
            'default_required_resources' => $type->relationLoaded('defaultResources')
                ? $type->defaultResources->map(fn (IncidentTypeDefaultResource $default) => $this->serializeDefaultResource($default))->values()->all()
                : [],
            'created_at' => $type->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeField(IncidentTypeField $field): array
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
            'created_at' => $field->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDefaultResource(IncidentTypeDefaultResource $default): array
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
