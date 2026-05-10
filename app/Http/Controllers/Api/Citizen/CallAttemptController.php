<?php

namespace App\Http\Controllers\Api\Citizen;

use App\Domain\Calls\Models\CallAttempt;
use App\Http\Controllers\Controller;
use App\Support\Calls\CallRoutingService;
use App\Support\Compatibility\LegacyCallerPayloadUsageLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CallAttemptController extends Controller
{
    public function __construct(
        private readonly CallRoutingService $callRouting,
        private readonly LegacyCallerPayloadUsageLogger $legacyCallerPayloads,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'citizen_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'citizen_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'caller_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'caller_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $this->legacyCallerPayloads->log(
            $request,
            'citizen.call-attempt',
            $this->legacyCallerFields($request, ['caller_latitude', 'caller_longitude']),
        );

        $latitude = $validated['citizen_latitude'] ?? $validated['caller_latitude'] ?? null;
        $longitude = $validated['citizen_longitude'] ?? $validated['caller_longitude'] ?? null;

        try {
            $result = $this->callRouting->startNewAttempt(
                $request->user(),
                $latitude !== null ? (float) $latitude : null,
                $longitude !== null ? (float) $longitude : null,
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

    /**
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    private function legacyCallerFields(Request $request, array $fields): array
    {
        return array_values(array_filter($fields, fn (string $field): bool => $request->exists($field)));
    }
}
