<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Users\Models\User;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\Calls\CallRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CallAttemptController extends Controller
{
    public function __construct(
        private readonly CallRoutingService $callRouting,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'citizen_id' => ['required', 'integer'],
            'incident_id' => ['nullable', 'integer'],
            'citizen_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'citizen_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'caller_id' => ['prohibited'],
            'caller_latitude' => ['prohibited'],
            'caller_longitude' => ['prohibited'],
        ]);

        $caller = User::query()
            ->whereIn('role', UserRole::citizenValues())
            ->findOrFail((int) $validated['citizen_id']);
        $latitude = $validated['citizen_latitude'] ?? null;
        $longitude = $validated['citizen_longitude'] ?? null;

        try {
            if (!empty($validated['incident_id'])) {
                $incident = Incident::query()->findOrFail((int) $validated['incident_id']);
                $result = $this->callRouting->startReconnectAttempt(
                    $request->user(),
                    $caller,
                    $incident,
                );
            } else {
                $result = $this->callRouting->startDirectedAttempt(
                    $request->user(),
                    $caller,
                    $latitude !== null ? (float) $latitude : null,
                    $longitude !== null ? (float) $longitude : null,
                );
            }
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $result['attempt'],
            'operator_attempt' => $result['operator_attempt'],
        ], 201);
    }

}
