<?php

namespace App\Http\Controllers\Api\Internal;

use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Support\Realtime\RealtimeEventPublishService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeProductQueryController extends Controller
{
    public function __construct(
        private readonly RealtimeEventPublishService $realtimeEvents,
        private readonly SettingsService $settings,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid product query secret.',
            ], 401);
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:product.query.request'],
            'schema_version' => ['nullable', 'integer', 'in:1'],
            'room' => ['required', 'string', 'max:255'],
            'request' => ['required', 'array'],
            'request.request_id' => ['required', 'string', 'max:128'],
            'request.query' => ['required', 'string', 'in:hotline.incident.snapshot'],
            'request.context' => ['nullable', 'array'],
            'request.context.incident_id' => ['required', 'integer', 'min:1'],
            'request.projection' => ['nullable', 'array'],
            'request.projection.preset' => ['nullable', 'string', 'in:status,call_state,summary'],
            'request.client_state' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
            'meta.sender' => ['nullable', 'array'],
            'meta.sender.user_id' => ['required', 'integer', 'min:1'],
        ]);

        $forwardedRequest = $validated['request'];
        $room = trim((string) $validated['room']);
        $requestId = trim((string) $forwardedRequest['request_id']);
        $query = trim((string) $forwardedRequest['query']);
        $incidentId = (int) ($forwardedRequest['context']['incident_id'] ?? 0);
        $senderUserId = (int) ($validated['meta']['sender']['user_id'] ?? 0);
        $projectionPreset = trim((string) ($forwardedRequest['projection']['preset'] ?? 'status')) ?: 'status';

        $payload = [
            'schema_version' => 1,
            'request_id' => $requestId,
            'query' => $query,
            'context' => [
                'incident_id' => $incidentId,
            ],
            'status' => 'ok',
            'data' => [
                'incident' => null,
            ],
        ];

        $incident = Incident::query()
            ->with(['operator', 'callSessions'])
            ->find($incidentId);

        if (! $incident || (int) $incident->citizen_id !== $senderUserId) {
            $payload['status'] = 'error';
            $payload['error'] = [
                'code' => 'hotline.incident.snapshot.forbidden',
                'message' => 'Incident snapshot is not available for this sender.',
            ];
        } else {
            $payload['data']['incident'] = $this->serializeIncidentSnapshot($incident, $projectionPreset);
        }

        $publish = $this->realtimeEvents->publishProductQueryResponse($room, $payload);

        return response()->json([
            'ok' => ($publish['status'] ?? null) === 'accepted',
            'status' => $publish['status'] ?? 'unknown',
            'request_id' => $requestId,
            'query' => $query,
            'publish' => $publish,
        ], ($publish['status'] ?? null) === 'accepted' ? 202 : 502);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIncidentSnapshot(Incident $incident, string $projectionPreset): array
    {
        $latestSession = $incident->callSessions
            ->sortBy('created_at')
            ->values()
            ->last();

        $snapshot = [
            'id' => (int) $incident->id,
            'status' => $incident->status->value,
            'updated_at' => $incident->updated_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
        ];

        if (in_array($projectionPreset, ['call_state', 'summary'], true)) {
            $snapshot['current_call_session'] = $latestSession ? [
                'id' => (int) $latestSession->id,
                'incident_id' => (int) $latestSession->incident_id,
                'citizen_id' => (int) $latestSession->citizen_id,
                'status' => $latestSession->status->value,
                'outcome' => $latestSession->outcome?->value,
                'answered_at' => $latestSession->answered_at?->toIso8601String(),
                'ended_at' => $latestSession->ended_at?->toIso8601String(),
                'updated_at' => $latestSession->updated_at?->toIso8601String(),
            ] : null;
        }

        if ($projectionPreset === 'summary') {
            $snapshot['display_id'] = str_pad((string) $incident->id, 6, '0', STR_PAD_LEFT);
            $snapshot['operator'] = $incident->operator ? [
                'id' => (int) $incident->operator->id,
                'name' => $incident->operator->name,
                'avatar' => $incident->operator->avatar,
            ] : null;
        }

        return $snapshot;
    }

    private function isAuthorized(Request $request): bool
    {
        $provided = trim((string) $request->header('X-Realtime-Backend-Secret', ''));
        $expected = trim((string) $this->settings->get('realtime_backend_ingress_secret', ''));

        return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
    }
}
