<?php

namespace App\Http\Controllers\Api\Citizen;

use App\Domain\Calls\Models\CallAttempt;
use App\Http\Controllers\Controller;
use App\Support\Calls\CallRoutingService;
use App\Support\Calls\NoAvailableOperatorException;
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
            'citizen_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'citizen_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'caller_latitude' => ['prohibited'],
            'caller_longitude' => ['prohibited'],
        ]);

        $latitude = $validated['citizen_latitude'] ?? null;
        $longitude = $validated['citizen_longitude'] ?? null;

        try {
            $result = $this->callRouting->startNewAttempt(
                $request->user(),
                $latitude !== null ? (float) $latitude : null,
                $longitude !== null ? (float) $longitude : null,
            );
        } catch (NoAvailableOperatorException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'reason' => 'no_available_operator',
                'fallback' => [
                    'available' => true,
                    'reason' => 'all_operators_busy',
                    'label' => 'Leave emergency details.',
                    'endpoint' => '/api/citizen/fallback-drops',
                ],
            ], 409);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
                'fallback' => [
                    'available' => false,
                ],
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $result['attempt'],
            'operator_attempt' => $result['operator_attempt'],
        ], 201);
    }

    public function cancel(Request $request, CallAttempt $attempt): JsonResponse
    {
        try {
            $attempt = $this->callRouting->cancelAttempt($request->user(), $attempt);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $attempt,
        ]);
    }

    public function timeout(Request $request, CallAttempt $attempt): JsonResponse
    {
        try {
            $attempt = $this->callRouting->timeoutAttempt($request->user(), $attempt);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $attempt,
        ]);
    }

}
