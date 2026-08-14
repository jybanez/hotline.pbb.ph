<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Callbacks\Models\CallbackCase;
use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Support\Callbacks\IncidentCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CallbackController extends Controller
{
    public function __construct(
        private readonly IncidentCallbackService $callbacks,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'items' => $this->callbacks->assignedOpenCases($request->user())
                ->map(fn (CallbackCase $case): array => $this->serializeCase($case))
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_id' => ['required', 'integer', 'exists:incidents,id'],
            'reason' => ['nullable', 'string', 'in:call_dropped,reconnect_required,operator_followup,other'],
            'priority' => ['nullable', 'string', 'in:normal,urgent'],
            'source_call_session_id' => ['nullable', 'integer', 'exists:call_sessions,id'],
        ]);

        try {
            $case = $this->callbacks->open(
                $request->user(),
                Incident::query()->findOrFail((int) $validated['incident_id']),
                $validated['reason'] ?? 'operator_followup',
                $validated['priority'] ?? 'normal',
                isset($validated['source_call_session_id']) ? (int) $validated['source_call_session_id'] : null,
            );
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        return response()->json([
            'ok' => true,
            'callback' => $this->serializeCase($case),
        ], $case->wasRecentlyCreated ? 201 : 200);
    }

    public function call(Request $request, CallbackCase $callbackCase): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->callbacks->startCall(
                $request->user(),
                $callbackCase,
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        return response()->json([
            'ok' => true,
            'callback' => $this->serializeCase($result['case']),
            'callback_attempt' => $this->serializeAttempt($result['attempt']),
            'attempt' => $result['call_attempt'] ?? null,
            'operator_attempt' => $result['operator_attempt'] ?? null,
        ], 201);
    }

    public function attempts(Request $request, CallbackCase $callbackCase): JsonResponse
    {
        $validated = $request->validate([
            'callback_attempt_id' => ['nullable', 'integer', 'exists:callback_attempts,id'],
            'result' => ['required', 'string', 'in:answered,no_answer,declined,unreachable,wrong_contact,technical_failure,cancelled'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $attempt = $this->callbacks->recordAttempt(
                $request->user(),
                $callbackCase,
                $validated['result'],
                isset($validated['callback_attempt_id']) ? (int) $validated['callback_attempt_id'] : null,
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        return response()->json([
            'ok' => true,
            'callback_attempt' => $this->serializeAttempt($attempt),
        ], $attempt->wasRecentlyCreated ? 201 : 200);
    }

    public function complete(Request $request, CallbackCase $callbackCase): JsonResponse
    {
        $validated = $request->validate([
            'final_disposition' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $case = $this->callbacks->complete(
                $request->user(),
                $callbackCase,
                $validated['final_disposition'],
            );
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage(), str_contains($exception->getMessage(), 'required') ? 422 : 409);
        }

        return response()->json([
            'ok' => true,
            'callback' => $this->serializeCase($case),
        ]);
    }

    private function failure(string $message, int $status = 409): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCase(CallbackCase $case): array
    {
        $case->loadMissing(['incident', 'citizen', 'operator', 'attempts']);
        $dueAt = $case->due_at;

        return [
            'id' => $case->id,
            'incident_id' => $case->incident_id,
            'display_id' => str_pad((string) $case->incident_id, 6, '0', STR_PAD_LEFT),
            'citizen_id' => $case->citizen_id,
            'citizen' => $case->citizen ? [
                'id' => $case->citizen->id,
                'name' => $case->citizen->name,
                'mobile' => $case->citizen->mobile,
                'avatar' => $case->citizen->avatar,
            ] : null,
            'operator_id' => $case->operator_id,
            'source_call_session_id' => $case->source_call_session_id,
            'reason' => $case->reason,
            'priority' => $case->priority,
            'status' => $case->status,
            'due_at' => $dueAt?->toIso8601String(),
            'is_overdue' => $dueAt !== null && $dueAt->isPast() && in_array($case->status, ['pending', 'in_progress'], true),
            'completed_at' => $case->completed_at?->toIso8601String(),
            'final_disposition' => $case->final_disposition,
            'attempts' => $case->attempts
                ->map(fn ($attempt): array => $this->serializeAttempt($attempt))
                ->values()
                ->all(),
            'created_at' => $case->created_at?->toIso8601String(),
            'updated_at' => $case->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttempt(object $attempt): array
    {
        return [
            'id' => $attempt->id,
            'callback_case_id' => $attempt->callback_case_id,
            'operator_id' => $attempt->operator_id,
            'attempt_number' => $attempt->attempt_number,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'ended_at' => $attempt->ended_at?->toIso8601String(),
            'channel' => $attempt->channel,
            'result' => $attempt->result,
            'call_attempt_id' => $attempt->call_attempt_id,
            'call_session_id' => $attempt->call_session_id,
            'note' => $attempt->note,
            'created_at' => $attempt->created_at?->toIso8601String(),
            'updated_at' => $attempt->updated_at?->toIso8601String(),
        ];
    }
}
