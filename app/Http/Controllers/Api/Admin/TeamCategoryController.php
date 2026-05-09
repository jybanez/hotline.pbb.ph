<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Teams\Models\TeamCategory;
use App\Http\Controllers\Controller;
use App\Support\Admin\BlockedDeleteInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamCategoryController extends Controller
{
    public function __construct(
        private readonly BlockedDeleteInspectorService $blockedDeletes,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = TeamCategory::query()
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

        $category = TeamCategory::query()->create($validated);

        return response()->json([
            'ok' => true,
            'category' => $category->fresh(),
        ], 201);
    }

    public function update(Request $request, TeamCategory $teamCategory): JsonResponse
    {
        $validated = $this->validatePayload($request, $teamCategory);

        $teamCategory->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'category' => $teamCategory->fresh(),
        ]);
    }

    public function destroy(TeamCategory $teamCategory): JsonResponse
    {
        $references = $this->blockedDeletes->referencesForTeamCategory($teamCategory);

        if ($references !== []) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$teamCategory->name}.",
                'references' => $references,
            ], 409);
        }

        $teamCategory->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array{name: string, description: string|null, sort_order: int}
     */
    private function validatePayload(Request $request, ?TeamCategory $category = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('team_categories', 'name')->ignore($category?->id),
            ],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
