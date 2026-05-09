<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Teams\Models\ResourceTypeCategory;
use App\Http\Controllers\Controller;
use App\Support\Admin\BlockedDeleteInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceTypeCategoryController extends Controller
{
    public function __construct(
        private readonly BlockedDeleteInspectorService $blockedDeletes,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = ResourceTypeCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $category = ResourceTypeCategory::query()->create($validated);

        return response()->json([
            'ok' => true,
            'category' => $category->fresh(),
        ], 201);
    }

    public function update(Request $request, ResourceTypeCategory $resourceTypeCategory): JsonResponse
    {
        $validated = $this->validatePayload($request, $resourceTypeCategory);

        $resourceTypeCategory->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'category' => $resourceTypeCategory->fresh(),
        ]);
    }

    public function destroy(ResourceTypeCategory $resourceTypeCategory): JsonResponse
    {
        $references = $this->blockedDeletes->referencesForResourceTypeCategory($resourceTypeCategory);

        if ($references !== []) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$resourceTypeCategory->name}.",
                'references' => $references,
            ], 409);
        }

        $resourceTypeCategory->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array{name: string, description: string|null, sort_order: int}
     */
    private function validatePayload(Request $request, ?ResourceTypeCategory $resourceTypeCategory = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('resource_type_categories', 'name')->ignore($resourceTypeCategory?->id),
            ],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
