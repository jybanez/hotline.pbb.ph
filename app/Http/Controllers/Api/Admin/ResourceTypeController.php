<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Teams\Models\ResourceType;
use App\Http\Controllers\Controller;
use App\Support\Admin\BlockedDeleteInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceTypeController extends Controller
{
    public function __construct(
        private readonly BlockedDeleteInspectorService $blockedDeletes,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = ResourceType::query()
            ->with('category')
            ->join('resource_type_categories', 'resource_types.category_id', '=', 'resource_type_categories.id')
            ->orderBy('resource_type_categories.sort_order')
            ->orderBy('resource_type_categories.name')
            ->orderBy('resource_types.name')
            ->select('resource_types.*')
            ->get()
            ->map(fn (ResourceType $resourceType) => $this->serializeResourceType($resourceType))
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $resourceType = ResourceType::query()->create($validated);

        return response()->json([
            'ok' => true,
            'resource_type' => $this->serializeResourceType($resourceType->fresh('category')),
        ], 201);
    }

    public function update(Request $request, ResourceType $resourceType): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $resourceType->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'resource_type' => $this->serializeResourceType($resourceType->fresh('category')),
        ]);
    }

    public function destroy(ResourceType $resourceType): JsonResponse
    {
        $references = $this->blockedDeletes->referencesForResourceType($resourceType);

        if ($references !== []) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$resourceType->name}.",
                'references' => $references,
            ], 409);
        }

        $resourceType->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array{category_id: int, name: string, unit_label: string|null}
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', 'exists:resource_type_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'unit_label' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResourceType(ResourceType $resourceType): array
    {
        return [
            'id' => $resourceType->id,
            'category_id' => $resourceType->category_id,
            'category' => $resourceType->category ? [
                'id' => $resourceType->category->id,
                'name' => $resourceType->category->name,
                'description' => $resourceType->category->description,
                'sort_order' => $resourceType->category->sort_order,
            ] : null,
            'name' => $resourceType->name,
            'unit_label' => $resourceType->unit_label,
            'created_at' => $resourceType->created_at?->toIso8601String(),
        ];
    }
}
