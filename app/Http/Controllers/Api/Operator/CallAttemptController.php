<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Users\Models\User;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\UserRole;
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
            'citizen_id' => ['nullable', 'required_without:caller_id', 'integer'],
            'caller_id' => ['nullable', 'required_without:citizen_id', 'integer'],
            'incident_id' => ['nullable', 'integer'],
            'citizen_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'citizen_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'caller_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'caller_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $this->legacyCallerPayloads->log(
            $request,
            'operator.call-attempt',
            $this->legacyCallerFields($request, ['caller_id', 'caller_latitude', 'caller_longitude']),
        );

        $caller = User::query()
            ->whereIn('role', UserRole::citizenValues())
            ->findOrFail((int) ($validated['citizen_id'] ?? $validated['caller_id']));
        $latitude = $validated['citizen_latitude'] ?? $validated['caller_latitude'] ?? null;
        $longitude = $validated['citizen_longitude'] ?? $validated['caller_longitude'] ?? null;

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

    /**
     * @param array<int, string> $fields
     * @return array<int, string>
     */
    private function legacyCallerFields(Request $request, array $fields): array
    {
        return array_values(array_filter($fields, fn (string $field): bool => $request->exists($field)));
    }
}
