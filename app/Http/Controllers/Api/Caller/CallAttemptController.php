<?php

namespace App\Http\Controllers\Api\Caller;

use App\Domain\Calls\Models\CallAttempt;
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
            'caller_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'caller_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        try {
            $result = $this->callRouting->startNewAttempt(
                $request->user(),
                isset($validated['caller_latitude']) ? (float) $validated['caller_latitude'] : null,
                isset($validated['caller_longitude']) ? (float) $validated['caller_longitude'] : null,
            );
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
