<?php

namespace App\Support\Callbacks;

use App\Domain\Callbacks\Models\CallbackAttempt;
use App\Domain\Callbacks\Models\CallbackCase;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Users\Models\User;
use App\Support\Calls\CallRoutingService;
use App\Support\Settings\SettingsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IncidentCallbackService
{
    public const OPEN_CASE_KEY = 'open';

    public const REASONS = [
        'call_dropped',
        'reconnect_required',
        'operator_followup',
        'other',
    ];

    public const PRIORITIES = [
        'normal',
        'urgent',
    ];

    public const STATUSES = [
        'pending',
        'in_progress',
        'completed',
        'cancelled',
    ];

    public const RESULTS = [
        'answered',
        'no_answer',
        'declined',
        'unreachable',
        'wrong_contact',
        'technical_failure',
        'cancelled',
    ];

    public function __construct(
        private readonly CallRoutingService $callRouting,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return Collection<int, CallbackCase>
     */
    public function assignedOpenCases(User $operator): Collection
    {
        return CallbackCase::query()
            ->with(['incident', 'citizen', 'operator', 'attempts'])
            ->whereHas('incident', fn ($query) => $query->where('operator_id', $operator->id))
            ->whereIn('status', ['pending', 'in_progress'])
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();
    }

    public function open(User $operator, Incident $incident, string $reason, string $priority = 'normal', ?int $sourceCallSessionId = null): CallbackCase
    {
        $reason = $this->normalizeIn($reason, self::REASONS, 'Invalid callback reason.');
        $priority = $this->normalizeIn($priority, self::PRIORITIES, 'Invalid callback priority.');

        return DB::transaction(function () use ($operator, $incident, $reason, $priority, $sourceCallSessionId): CallbackCase {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $this->assertAssignedOperator($operator, $incident);
            $this->assertCallbackOpenStatus($incident);
            $this->assertSourceCallSessionMatchesIncident($sourceCallSessionId, $incident);

            $existing = CallbackCase::query()
                ->with(['incident', 'citizen', 'operator', 'attempts'])
                ->where('incident_id', $incident->id)
                ->where('reason', $reason)
                ->where('open_case_key', self::OPEN_CASE_KEY)
                ->first();

            if ($existing) {
                return $existing;
            }

            $case = CallbackCase::query()->create([
                'incident_id' => $incident->id,
                'citizen_id' => $incident->citizen_id,
                'operator_id' => $operator->id,
                'source_call_session_id' => $sourceCallSessionId,
                'reason' => $reason,
                'priority' => $priority,
                'status' => 'pending',
                'open_case_key' => self::OPEN_CASE_KEY,
                'due_at' => now()->addSeconds($this->firstSlaSeconds()),
            ]);

            $freshCase = $case->fresh(['incident', 'citizen', 'operator', 'attempts']);
            $freshCase->wasRecentlyCreated = true;

            return $freshCase;
        });
    }

    /**
     * @return array{case: CallbackCase, attempt: CallbackAttempt, call_attempt?: mixed, operator_attempt?: mixed}
     */
    public function startCall(User $operator, CallbackCase $case, ?string $note = null): array
    {
        $case->loadMissing(['incident.citizen', 'citizen', 'attempts']);
        $incident = $case->incident;

        if (! $incident) {
            throw new RuntimeException('Callback incident is missing.');
        }

        $this->assertAssignedOperator($operator, $incident);
        $this->assertOpenCase($case);
        $this->assertCallbackOpenStatus($incident);

        $attempt = $this->createAttempt($operator, $case, [
            'started_at' => now(),
            'channel' => 'pbb_call',
            'note' => $note,
        ]);

        try {
            $result = $this->callRouting->startReconnectAttempt($operator, $case->citizen, $incident);
        } catch (RuntimeException $exception) {
            $attempt->forceFill([
                'ended_at' => now(),
                'result' => 'technical_failure',
                'note' => trim(($note ? $note."\n" : '').$exception->getMessage()),
            ])->save();

            $this->markInProgress($case);

            throw $exception;
        }

        $attempt->forceFill([
            'call_attempt_id' => $result['attempt']->id,
        ])->save();

        $case = $this->markInProgress($case);

        return [
            'case' => $case,
            'attempt' => $attempt->fresh(),
            'call_attempt' => $result['attempt'],
            'operator_attempt' => $result['operator_attempt'],
        ];
    }

    public function recordAttempt(User $operator, CallbackCase $case, string $result, ?int $callbackAttemptId = null, ?string $note = null): CallbackAttempt
    {
        $result = $this->normalizeIn($result, self::RESULTS, 'Invalid callback attempt result.');
        $case->loadMissing('incident');
        $incident = $case->incident;

        if (! $incident) {
            throw new RuntimeException('Callback incident is missing.');
        }

        $this->assertAssignedOperator($operator, $incident);
        $this->assertOpenCase($case);

        if ($callbackAttemptId !== null) {
            $attempt = CallbackAttempt::query()
                ->where('callback_case_id', $case->id)
                ->whereKey($callbackAttemptId)
                ->firstOrFail();

            $attempt->forceFill([
                'operator_id' => $operator->id,
                'result' => $result,
                'ended_at' => $attempt->ended_at ?? now(),
                'note' => $note ?? $attempt->note,
            ])->save();

            $this->markInProgress($case);

            return $attempt->fresh();
        }

        $attempt = $this->createAttempt($operator, $case, [
            'started_at' => now(),
            'ended_at' => now(),
            'channel' => 'pbb_call',
            'result' => $result,
            'note' => $note,
        ]);

        $this->markInProgress($case);

        return $attempt;
    }

    public function complete(User $operator, CallbackCase $case, string $finalDisposition): CallbackCase
    {
        $finalDisposition = trim($finalDisposition);

        if ($finalDisposition === '') {
            throw new RuntimeException('Final disposition is required to complete a callback.');
        }

        $case->loadMissing('incident');
        $incident = $case->incident;

        if (! $incident) {
            throw new RuntimeException('Callback incident is missing.');
        }

        $this->assertAssignedOperator($operator, $incident);
        $this->assertOpenCase($case);

        return DB::transaction(function () use ($case, $finalDisposition): CallbackCase {
            $lockedCase = CallbackCase::query()->lockForUpdate()->findOrFail($case->id);
            $lockedCase->forceFill([
                'status' => 'completed',
                'open_case_key' => null,
                'completed_at' => now(),
                'final_disposition' => $finalDisposition,
            ])->save();

            return $lockedCase->fresh(['incident', 'citizen', 'operator', 'attempts']);
        });
    }

    private function createAttempt(User $operator, CallbackCase $case, array $attributes): CallbackAttempt
    {
        return DB::transaction(function () use ($operator, $case, $attributes): CallbackAttempt {
            $lockedCase = CallbackCase::query()->lockForUpdate()->findOrFail($case->id);
            $nextAttemptNumber = ((int) $lockedCase->attempts()->max('attempt_number')) + 1;

            $attempt = CallbackAttempt::query()->create([
                'callback_case_id' => $lockedCase->id,
                'operator_id' => $operator->id,
                'attempt_number' => $nextAttemptNumber,
                ...$attributes,
            ]);
            $freshAttempt = $attempt->fresh();
            $freshAttempt->wasRecentlyCreated = true;

            return $freshAttempt;
        });
    }

    private function markInProgress(CallbackCase $case): CallbackCase
    {
        if ($case->status === 'pending') {
            $case->forceFill(['status' => 'in_progress'])->save();
        }

        return $case->fresh(['incident', 'citizen', 'operator', 'attempts']);
    }

    private function assertAssignedOperator(User $operator, Incident $incident): void
    {
        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('Operator is not assigned to this incident.');
        }
    }

    private function assertCallbackOpenStatus(Incident $incident): void
    {
        if (! in_array($incident->status, [IncidentStatus::Active, IncidentStatus::Deferred], true)) {
            throw new RuntimeException('Callback is only available for active or deferred incidents.');
        }
    }

    private function assertOpenCase(CallbackCase $case): void
    {
        if (! in_array($case->status, ['pending', 'in_progress'], true)) {
            throw new RuntimeException('This callback case is already closed.');
        }
    }

    private function assertSourceCallSessionMatchesIncident(?int $sourceCallSessionId, Incident $incident): void
    {
        if ($sourceCallSessionId === null) {
            return;
        }

        $callSession = CallSession::query()->find($sourceCallSessionId);

        if (
            ! $callSession
            || (int) $callSession->incident_id !== (int) $incident->id
            || (int) $callSession->citizen_id !== (int) $incident->citizen_id
        ) {
            throw new RuntimeException('Source call session does not belong to this callback incident.');
        }
    }

    private function normalizeIn(string $value, array $allowed, string $message): string
    {
        $value = trim($value);

        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function firstSlaSeconds(): int
    {
        return max(1, (int) $this->settings->get('callback_first_sla_seconds', 30));
    }
}
