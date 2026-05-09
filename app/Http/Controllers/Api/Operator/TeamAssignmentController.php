<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Teams\Models\TeamAssignment;
use App\Http\Controllers\Controller;
use App\Support\Teams\TeamAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TeamAssignmentController extends Controller
{
    public function __construct(
        private readonly TeamAssignmentService $assignments,
    ) {
    }

    public function store(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'resources' => ['nullable', 'array'],
            'resources.*.resource_type_id' => ['required_with:resources', 'integer'],
            'resources.*.quantity_allocated' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $assignment = $this->assignments->assign(
                $request->user(),
                $incident,
                (int) $validated['team_id'],
                $validated['contact_person'] ?? null,
                $validated['resources'] ?? [],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'assignment' => $assignment,
        ], 201);
    }

    public function update(Request $request, TeamAssignment $assignment): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:assigned,requested,accepted,en_route,on_scene,completed,cancelled,Assigned,Requested,Accepted,En-route,On-Scene,Completed,Cancelled'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'cancel_reason_code' => ['nullable', 'string', 'in:mechanical_issue,rerouted_higher_priority,safety_risk,no_contact,resource_unavailable,incorrect_dispatch,other'],
            'cancel_reason_note' => ['nullable', 'string'],
            'resources' => ['nullable', 'array'],
            'resources.*.resource_type_id' => ['required_with:resources', 'integer'],
            'resources.*.quantity_allocated' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $assignment = $this->assignments->update($request->user(), $assignment, $validated);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'assignment' => $assignment,
        ]);
    }

    public function storeNote(Request $request, TeamAssignment $assignment): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string'],
        ]);

        try {
            $assignment = $this->assignments->addNote($request->user(), $assignment, (string) $validated['note']);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'assignment' => $assignment,
        ], 201);
    }

    public function destroy(Request $request, TeamAssignment $assignment): JsonResponse
    {
        try {
            $this->assignments->delete($request->user(), $assignment);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
        ]);
    }
}
