<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Incidents\Models\IncidentCategory;
use App\Http\Controllers\Controller;
use App\Support\Admin\BlockedDeleteInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IncidentCategoryController extends Controller
{
    public function __construct(
        private readonly BlockedDeleteInspectorService $blockedDeletes,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = IncidentCategory::query()
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

        $category = IncidentCategory::query()->create($validated);

        return response()->json([
            'ok' => true,
            'category' => $category->fresh(),
        ], 201);
    }

    public function update(Request $request, IncidentCategory $category): JsonResponse
    {
        $validated = $this->validatePayload($request, $category);

        $category->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'category' => $category->fresh(),
        ]);
    }

    public function destroy(IncidentCategory $category): JsonResponse
    {
        $references = $this->blockedDeletes->referencesForIncidentCategory($category);

        if ($references !== []) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$category->name}.",
                'references' => $references,
            ], 409);
        }

        $category->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array{name: string, description: string|null, sort_order: int}
     */
    private function validatePayload(Request $request, ?IncidentCategory $category = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('incident_categories', 'name')->ignore($category?->id),
            ],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
