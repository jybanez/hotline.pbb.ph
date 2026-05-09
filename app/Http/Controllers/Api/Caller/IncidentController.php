<?php

namespace App\Http\Controllers\Api\Caller;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Support\Incidents\IncidentPayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentPayloadBuilder $incidentPayloads,
    ) {
    }

    public function current(Request $request): JsonResponse
    {
        $incident = Incident::query()
            ->where('caller_id', $request->user()->id)
            ->whereIn('status', [IncidentStatus::Active, IncidentStatus::Deferred])
            ->latest('id')
            ->first();

        return response()->json([
            'incident' => $incident ? $this->incidentPayloads->buildWorkbenchPayload($incident, $request->user()) : null,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $currentOpenIncidentId = Incident::query()
            ->where('caller_id', $request->user()->id)
            ->whereIn('status', [IncidentStatus::Active, IncidentStatus::Deferred])
            ->latest('id')
            ->value('id');

        $history = Incident::query()
            ->where('caller_id', $request->user()->id)
            ->when($currentOpenIncidentId, fn ($query) => $query->where('id', '!=', $currentOpenIncidentId))
            ->latest('id')
            ->get();

        return response()->json([
            'items' => $this->incidentPayloads->buildHistoryList($history),
        ]);
    }

    public function show(Request $request, Incident $incident): JsonResponse
    {
        abort_unless((int) $incident->caller_id === (int) $request->user()->id, 404);

        return response()->json(
            $this->incidentPayloads->buildWorkbenchPayload($incident, $request->user()),
        );
    }
}
