<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Teams\Models\ResourceType;
use App\Domain\Teams\Models\Team;
use App\Http\Controllers\Controller;
use App\Support\Admin\BlockedDeleteInspectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __construct(
        private readonly BlockedDeleteInspectorService $blockedDeletes,
    ) {
    }

    public function index(): JsonResponse
    {
        $items = Team::query()
            ->select('teams.*')
            ->with('category')
            ->withCount('inventories')
            ->join('team_categories', 'teams.team_category_id', '=', 'team_categories.id')
            ->orderBy('team_categories.sort_order')
            ->orderBy('team_categories.name')
            ->orderBy('teams.name')
            ->get()
            ->map(fn (Team $team) => $this->serializeTeam($team))
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    public function show(Team $team): JsonResponse
    {
        $team->load([
            'category',
            'inventories.resourceType.category',
        ])->loadCount('inventories');

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
            'team' => $this->serializeTeam($team),
            'inventories' => $team->inventories->map(fn ($inventory) => [
                'id' => $inventory->id,
                'team_id' => $inventory->team_id,
                'resource_type_id' => $inventory->resource_type_id,
                'resource_name' => $inventory->resourceType?->name,
                'resource_category_name' => $inventory->resourceType?->category?->name,
                'quantity_available' => $inventory->quantity_available,
                'created_at' => $inventory->created_at?->toIso8601String(),
                'updated_at' => $inventory->updated_at?->toIso8601String(),
            ])->values()->all(),
            'resource_type_options' => $resourceTypeOptions,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $team = Team::query()->create($validated);

        return response()->json([
            'ok' => true,
            'team' => $this->serializeTeam($team->fresh('category')),
        ], 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $team->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'team' => $this->serializeTeam($team->fresh('category')),
        ]);
    }

    public function destroy(Team $team): JsonResponse
    {
        $references = $this->blockedDeletes->referencesForTeam($team);

        if ($references !== []) {
            return response()->json([
                'ok' => false,
                'message' => "Delete blocked for {$team->name}.",
                'references' => $references,
            ], 409);
        }

        $team->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array{team_category_id: int, name: string, status: string}
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'team_category_id' => ['required', 'integer', 'exists:team_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:50'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTeam(Team $team): array
    {
        return [
            'id' => $team->id,
            'team_category_id' => $team->team_category_id,
            'category_name' => $team->category?->name,
            'name' => $team->name,
            'status' => $team->status,
            'inventory_count' => $team->inventories_count ?? null,
            'created_at' => $team->created_at?->toIso8601String(),
        ];
    }
}
