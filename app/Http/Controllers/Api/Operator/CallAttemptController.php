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
            'caller_id' => ['required', 'integer'],
            'incident_id' => ['nullable', 'integer'],
            'caller_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'caller_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $caller = User::query()
            ->whereIn('role', UserRole::citizenValues())
            ->findOrFail((int) $validated['caller_id']);

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
                    isset($validated['caller_latitude']) ? (float) $validated['caller_latitude'] : null,
                    isset($validated['caller_longitude']) ? (float) $validated['caller_longitude'] : null,
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
