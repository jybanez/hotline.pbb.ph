<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Teams\Models\Team;
use App\Domain\Teams\Models\TeamResourceInventory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamInventoryController extends Controller
{
    public function store(Request $request, Team $team): JsonResponse
    {
        $validated = $this->validatePayload($request, $team);

        $inventory = $team->inventories()->create($validated);

        return response()->json([
            'ok' => true,
            'inventory' => $this->serializeInventory($inventory->fresh('resourceType.category')),
        ], 201);
    }

    public function update(Request $request, Team $team, TeamResourceInventory $inventory): JsonResponse
    {
        abort_unless((int) $inventory->team_id === (int) $team->id, 404);

        $validated = $this->validatePayload($request, $team, $inventory);

        $inventory->fill($validated)->save();

        return response()->json([
            'ok' => true,
            'inventory' => $this->serializeInventory($inventory->fresh('resourceType.category')),
        ]);
    }

    public function destroy(Team $team, TeamResourceInventory $inventory): JsonResponse
    {
        abort_unless((int) $inventory->team_id === (int) $team->id, 404);

        $inventory->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @return array{resource_type_id:int,quantity_available:int}
     */
    private function validatePayload(Request $request, Team $team, ?TeamResourceInventory $inventory = null): array
    {
        return $request->validate([
            'resource_type_id' => [
                'required',
                'integer',
                'exists:resource_types,id',
                Rule::unique('team_resource_inventories', 'resource_type_id')
                    ->where(fn ($query) => $query->where('team_id', $team->id))
                    ->ignore($inventory?->id),
            ],
            'quantity_available' => ['required', 'integer', 'min:0'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeInventory(TeamResourceInventory $inventory): array
    {
        return [
            'id' => $inventory->id,
            'team_id' => $inventory->team_id,
            'resource_type_id' => $inventory->resource_type_id,
            'resource_name' => $inventory->resourceType?->name,
            'resource_category_name' => $inventory->resourceType?->category?->name,
            'quantity_available' => $inventory->quantity_available,
            'created_at' => $inventory->created_at?->toIso8601String(),
            'updated_at' => $inventory->updated_at?->toIso8601String(),
        ];
    }
}
