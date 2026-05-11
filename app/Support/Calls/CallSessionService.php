<?php

namespace App\Support\Calls;

use App\Domain\Calls\Models\CallParticipant;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\OperatorRuntimeState;
use App\Domain\Users\Models\User;
use App\Support\Sessions\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CallSessionService
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {}

    public function startReconnect(User $caller, Incident $incident): CallSession
    {
        if ((int) $incident->citizen_id !== (int) $caller->id) {
            throw new RuntimeException('You cannot reconnect to this incident.');
        }

        if (! in_array($incident->status, [IncidentStatus::Active, IncidentStatus::Deferred], true)) {
            throw new RuntimeException('This incident is no longer open for reconnect.');
        }

        $operator = $incident->operator;

        if (! $operator) {
            throw new RuntimeException('This incident has no assigned operator.');
        }

        $runtimeState = $this->availability->operatorRuntimeState($operator);
        $engagedOnAnotherIncident = Incident::query()
            ->where('operator_id', $operator->id)
            ->where('status', IncidentStatus::Active)
            ->where('id', '!=', $incident->id)
            ->exists();

        $hasOpenReconnect = CallSession::query()
            ->where('incident_id', $incident->id)
            ->whereIn('status', [CallStatus::Calling, CallStatus::InProgress])
            ->exists();

        $isEligible = $runtimeState === OperatorRuntimeState::Available->value
            || ($incident->status === IncidentStatus::Active && ! $engagedOnAnotherIncident);

        if (! $isEligible) {
            throw new RuntimeException('Reconnect is blocked because the assigned operator is currently busy.');
        }

        if ($hasOpenReconnect) {
            throw new RuntimeException('Reconnect is already in progress for this incident.');
        }

        return DB::transaction(function () use ($caller, $incident) {
            $callSession = CallSession::query()->create([
                'incident_id' => $incident->id,
                'citizen_id' => $caller->id,
                'status' => CallStatus::Calling,
                'started_at' => now(),
            ]);

            CallParticipant::query()->create([
                'call_session_id' => $callSession->id,
                'user_id' => $caller->id,
                'participant_role' => 'citizen',
                'joined_at' => now(),
                'created_at' => now(),
            ]);

            return $callSession->fresh(['participants']);
        });
    }

    public function cancelUnansweredReconnect(User $caller, CallSession $callSession): CallSession
    {
        $callSession->loadMissing('incident', 'participants');

        if ((int) $callSession->citizen_id !== (int) $caller->id) {
            throw new RuntimeException('You cannot cancel this reconnect request.');
        }

        if ($callSession->status !== CallStatus::Calling || $callSession->answered_at !== null) {
            throw new RuntimeException('This reconnect call session can no longer be cancelled.');
        }

        return DB::transaction(function () use ($callSession) {
            $callSession->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::CancelledByCitizen,
                'ended_at' => now(),
            ])->save();

            $callSession->participants()
                ->whereNull('left_at')
                ->update([
                    'left_at' => now(),
                ]);

            return $callSession->fresh(['participants']);
        });
    }

    public function answerReconnect(User $operator, CallSession $callSession): CallSession
    {
        $callSession->loadMissing('incident', 'participants');
        $incident = $callSession->incident;

        if (! $incident || (int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot answer this reconnect call.');
        }

        if ($callSession->status !== CallStatus::Calling || $callSession->answered_at !== null) {
            throw new RuntimeException('This reconnect call is no longer answerable.');
        }

        return DB::transaction(function () use ($operator, $callSession) {
            $callSession->forceFill([
                'status' => CallStatus::InProgress,
                'outcome' => CallOutcome::Answered,
                'answered_at' => null,
            ])->save();

            CallParticipant::query()->create([
                'call_session_id' => $callSession->id,
                'user_id' => $operator->id,
                'participant_role' => 'operator',
                'joined_at' => now(),
                'created_at' => now(),
            ]);

            return $callSession->fresh(['participants']);
        });
    }

    public function markReady(User $operator, CallSession $callSession, ?string $answeredAt = null): CallSession
    {
        $callSession->loadMissing('incident', 'participants');
        $incident = $callSession->incident;

        if (! $incident || (int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot update this call session.');
        }

        if ($callSession->status !== CallStatus::InProgress) {
            throw new RuntimeException('This call session is not currently active.');
        }

        if ($callSession->answered_at !== null) {
            return $callSession->fresh(['participants']);
        }

        return DB::transaction(function () use ($callSession, $answeredAt) {
            $resolvedAnsweredAt = $this->resolveReadyTimestamp($callSession, $answeredAt);

            $callSession->forceFill([
                'answered_at' => $resolvedAnsweredAt,
            ])->save();

            return $callSession->fresh(['participants']);
        });
    }

    private function resolveReadyTimestamp(CallSession $callSession, ?string $answeredAt): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $appTimezone = (string) config('app.timezone', 'UTC');
        $lowerBound = $callSession->started_at
            ? CarbonImmutable::instance($callSession->started_at)->setTimezone($appTimezone)
            : $now;

        if ($answeredAt === null || trim($answeredAt) === '') {
            return $now;
        }

        try {
            $candidate = CarbonImmutable::parse($answeredAt)->setTimezone($appTimezone);
        } catch (\Throwable) {
            return $now;
        }

        if ($candidate->lt($lowerBound)) {
            return $lowerBound;
        }

        if ($candidate->gt($now)) {
            return $now;
        }

        return $candidate;
    }

    public function endActiveCallerSession(User $caller, CallSession $callSession): CallSession
    {
        $callSession->loadMissing('incident', 'participants');

        if ((int) $callSession->citizen_id !== (int) $caller->id) {
            throw new RuntimeException('You cannot end this call session.');
        }

        if ($callSession->status !== CallStatus::InProgress) {
            throw new RuntimeException('This call session is not currently active.');
        }

        return DB::transaction(function () use ($callSession) {
            $callSession->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::EndedByCitizen,
                'ended_at' => now(),
            ])->save();

            $callSession->participants()
                ->whereNull('left_at')
                ->update([
                    'left_at' => now(),
                ]);

            return $callSession->fresh(['participants']);
        });
    }

    public function endActiveOperatorDisconnectedSession(User $caller, CallSession $callSession): CallSession
    {
        $callSession->loadMissing('incident', 'participants');

        if ((int) $callSession->citizen_id !== (int) $caller->id) {
            throw new RuntimeException('You cannot end this call session.');
        }

        if ($callSession->status !== CallStatus::InProgress) {
            throw new RuntimeException('This call session is not currently active.');
        }

        return DB::transaction(function () use ($callSession) {
            $callSession->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::EndedByOperator,
                'ended_at' => now(),
            ])->save();

            $callSession->participants()
                ->whereNull('left_at')
                ->update([
                    'left_at' => now(),
                ]);

            return $callSession->fresh(['participants']);
        });
    }

    public function endActiveOperatorSession(User $operator, CallSession $callSession): CallSession
    {
        return $this->endActiveSessionForOperator(
            $operator,
            $callSession,
            CallOutcome::EndedByOperator,
        );
    }

    public function endActiveCitizenDisconnectedSession(User $operator, CallSession $callSession): CallSession
    {
        return $this->endActiveSessionForOperator(
            $operator,
            $callSession,
            CallOutcome::EndedByCitizen,
        );
    }

    private function endActiveSessionForOperator(
        User $operator,
        CallSession $callSession,
        CallOutcome $outcome,
    ): CallSession {
        $callSession->loadMissing('incident', 'participants');
        $incident = $callSession->incident;

        if (! $incident || (int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot end this call session.');
        }

        if ($callSession->status !== CallStatus::InProgress) {
            throw new RuntimeException('This call session is not currently active.');
        }

        return DB::transaction(function () use ($callSession, $outcome) {
            $callSession->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => $outcome,
                'ended_at' => now(),
            ])->save();

            $callSession->participants()
                ->whereNull('left_at')
                ->update([
                    'left_at' => now(),
                ]);

            return $callSession->fresh(['participants']);
        });
    }
}
