<?php

namespace App\Support\Calls;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallAttemptOperatorAttempt;
use App\Domain\Calls\Models\CallParticipant;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\OperatorRuntimeState;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Domain\Users\Models\User;
use App\Support\Sessions\AvailabilityService;
use App\Support\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CallRoutingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{attempt: CallAttempt, operator_attempt: CallAttemptOperatorAttempt}
     */
    public function startNewAttempt(User $caller, ?float $latitude = null, ?float $longitude = null): array
    {
        $availability = $this->availability->callerAvailability();

        if (($availability['status'] ?? 'red') !== 'green') {
            throw new RuntimeException('Hotline is not currently available for new calls.');
        }

        $operator = User::query()
            ->where('role', UserRole::Operator)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->first(fn (User $candidate) => $this->availability->operatorRuntimeState($candidate) === OperatorRuntimeState::Available->value);

        if (! $operator) {
            throw new RuntimeException('No operator is currently available.');
        }

        return DB::transaction(function () use ($caller, $latitude, $longitude, $operator) {
            $attempt = CallAttempt::query()->create([
                'caller_id' => $caller->id,
                'citizen_id' => $caller->id,
                'status' => CallStatus::Calling,
                'caller_latitude' => $latitude,
                'caller_longitude' => $longitude,
                'started_at' => now(),
            ]);

            $operatorAttempt = $attempt->operatorAttempts()->create([
                'operator_id' => $operator->id,
                'status' => CallStatus::Calling,
                'started_at' => now(),
                'created_at' => now(),
            ]);

            return [
                'attempt' => $attempt->fresh(),
                'operator_attempt' => $operatorAttempt->fresh(),
            ];
        });
    }

    /**
     * @return array{attempt: CallAttempt, operator_attempt: CallAttemptOperatorAttempt}
     */
    public function startDirectedAttempt(User $operator, User $caller, ?float $latitude = null, ?float $longitude = null): array
    {
        if ($operator->role !== UserRole::Operator || $operator->status !== UserStatus::Active) {
            throw new RuntimeException('Operator is not eligible to receive calls.');
        }

        if (! $caller->role->isCitizen() || $caller->status !== UserStatus::Active) {
            throw new RuntimeException('Caller is not eligible to start a call.');
        }

        return DB::transaction(function () use ($caller, $latitude, $longitude, $operator) {
            $attempt = CallAttempt::query()->create([
                'caller_id' => $caller->id,
                'citizen_id' => $caller->id,
                'status' => CallStatus::Calling,
                'caller_latitude' => $latitude,
                'caller_longitude' => $longitude,
                'started_at' => now(),
            ]);

            $operatorAttempt = $attempt->operatorAttempts()->create([
                'operator_id' => $operator->id,
                'status' => CallStatus::Calling,
                'started_at' => now(),
                'created_at' => now(),
            ]);

            return [
                'attempt' => $attempt->fresh(),
                'operator_attempt' => $operatorAttempt->fresh(),
            ];
        });
    }

    /**
     * @return array{attempt: CallAttempt, operator_attempt: CallAttemptOperatorAttempt}
     */
    public function startReconnectAttempt(User $operator, User $caller, Incident $incident): array
    {
        if ($operator->role !== UserRole::Operator || $operator->status !== UserStatus::Active) {
            throw new RuntimeException('Operator is not eligible to receive calls.');
        }

        if (! $caller->role->isCitizen() || $caller->status !== UserStatus::Active) {
            throw new RuntimeException('Caller is not eligible to start a reconnect call.');
        }

        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('Operator is not assigned to this incident.');
        }

        if ((int) $incident->citizen_id !== (int) $caller->id) {
            throw new RuntimeException('Caller does not match this incident.');
        }

        if (! in_array($incident->status, [IncidentStatus::Active, IncidentStatus::Deferred], true)) {
            throw new RuntimeException('This incident is no longer open for reconnect.');
        }

        $hasOpenAttempt = CallAttempt::query()
            ->where('incident_id', $incident->id)
            ->where('status', CallStatus::Calling)
            ->exists();

        if ($hasOpenAttempt) {
            throw new RuntimeException('Reconnect is already in progress for this incident.');
        }

        $hasOpenSession = CallSession::query()
            ->where('incident_id', $incident->id)
            ->whereIn('status', [CallStatus::Calling, CallStatus::InProgress])
            ->exists();

        if ($hasOpenSession) {
            throw new RuntimeException('Reconnect is already in progress for this incident.');
        }

        return DB::transaction(function () use ($caller, $incident, $operator) {
            $attempt = CallAttempt::query()->create([
                'caller_id' => $caller->id,
                'citizen_id' => $caller->id,
                'incident_id' => $incident->id,
                'status' => CallStatus::Calling,
                'started_at' => now(),
            ]);

            $operatorAttempt = $attempt->operatorAttempts()->create([
                'operator_id' => $operator->id,
                'status' => CallStatus::Calling,
                'started_at' => now(),
                'created_at' => now(),
            ]);

            return [
                'attempt' => $attempt->fresh(),
                'operator_attempt' => $operatorAttempt->fresh(),
            ];
        });
    }

    public function cancelAttempt(User $caller, CallAttempt $attempt): CallAttempt
    {
        if ((int) $attempt->citizen_id !== (int) $caller->id) {
            throw new RuntimeException('You cannot cancel this call attempt.');
        }

        if ($attempt->incident_id !== null || $attempt->status !== CallStatus::Calling) {
            throw new RuntimeException('This call attempt can no longer be cancelled.');
        }

        return DB::transaction(function () use ($attempt) {
            $attempt->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::CancelledByCitizen,
                'ended_at' => now(),
            ])->save();

            $attempt->operatorAttempts()
                ->where('status', CallStatus::Calling)
                ->update([
                    'status' => CallStatus::Ended,
                    'outcome' => CallOutcome::CancelledByCitizen,
                    'ended_at' => now(),
                ]);

            return $attempt->fresh(['operatorAttempts']);
        });
    }

    public function timeoutAttempt(User $caller, CallAttempt $attempt): CallAttempt
    {
        if ((int) $attempt->citizen_id !== (int) $caller->id) {
            throw new RuntimeException('You cannot timeout this call attempt.');
        }

        if ($attempt->incident_id !== null || $attempt->status !== CallStatus::Calling) {
            throw new RuntimeException('This call attempt can no longer be timed out.');
        }

        return DB::transaction(function () use ($attempt) {
            $attempt->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::TimedOut,
                'ended_at' => now(),
            ])->save();

            $attempt->operatorAttempts()
                ->where('status', CallStatus::Calling)
                ->update([
                    'status' => CallStatus::Ended,
                    'outcome' => CallOutcome::TimedOut,
                    'ended_at' => now(),
                ]);

            return $attempt->fresh(['operatorAttempts']);
        });
    }

    public function cancelDirectedAttemptFromCaller(User $operator, CallAttemptOperatorAttempt $operatorAttempt): CallAttempt
    {
        $operatorAttempt->loadMissing('callAttempt');
        $attempt = $operatorAttempt->callAttempt;

        if ((int) $operatorAttempt->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot update this routed call.');
        }

        if (! $attempt || $attempt->status !== CallStatus::Calling) {
            throw new RuntimeException('This call attempt is no longer cancellable.');
        }

        return DB::transaction(function () use ($attempt, $operatorAttempt) {
            $attempt->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::CancelledByCitizen,
                'ended_at' => now(),
            ])->save();

            $operatorAttempt->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::CancelledByCitizen,
                'ended_at' => now(),
            ])->save();

            $attempt->operatorAttempts()
                ->whereKeyNot($operatorAttempt->id)
                ->where('status', CallStatus::Calling)
                ->update([
                    'status' => CallStatus::Ended,
                    'outcome' => CallOutcome::CancelledByCitizen,
                    'ended_at' => now(),
                ]);

            return $attempt->fresh(['operatorAttempts']);
        });
    }

    public function declineNewAttempt(User $operator, CallAttemptOperatorAttempt $operatorAttempt): CallAttempt
    {
        return $this->endNewAttemptForOperator(
            $operator,
            $operatorAttempt,
            CallOutcome::DeclinedByOperator,
            'decline',
        );
    }

    public function timeoutNewAttemptForOperator(User $operator, CallAttemptOperatorAttempt $operatorAttempt): CallAttempt
    {
        return $this->endNewAttemptForOperator(
            $operator,
            $operatorAttempt,
            CallOutcome::TimedOut,
            'timeout',
        );
    }

    private function endNewAttemptForOperator(
        User $operator,
        CallAttemptOperatorAttempt $operatorAttempt,
        CallOutcome $outcome,
        string $action,
    ): CallAttempt {
        $operatorAttempt->loadMissing('callAttempt');
        $attempt = $operatorAttempt->callAttempt;

        if ((int) $operatorAttempt->operator_id !== (int) $operator->id) {
            throw new RuntimeException("You cannot {$action} this routed call.");
        }

        if (! $attempt || $attempt->status !== CallStatus::Calling) {
            throw new RuntimeException("This call attempt is no longer {$action}able.");
        }

        return DB::transaction(function () use ($attempt, $operatorAttempt, $outcome) {
            $attempt->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => $outcome,
                'ended_at' => now(),
            ])->save();

            $operatorAttempt->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => $outcome,
                'ended_at' => now(),
            ])->save();

            $attempt->operatorAttempts()
                ->whereKeyNot($operatorAttempt->id)
                ->where('status', CallStatus::Calling)
                ->update([
                    'status' => CallStatus::Ended,
                    'outcome' => CallOutcome::TimedOut,
                    'ended_at' => now(),
                ]);

            return $attempt->fresh(['operatorAttempts']);
        });
    }

    /**
     * @return array{attempt: CallAttempt, incident: Incident, call_session: CallSession}
     */
    public function answerNewAttempt(User $operator, CallAttemptOperatorAttempt $operatorAttempt): array
    {
        $operatorAttempt->loadMissing('callAttempt');
        $attempt = $operatorAttempt->callAttempt;

        if ((int) $operatorAttempt->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot answer this routed call.');
        }

        if (! $attempt || $attempt->status !== CallStatus::Calling) {
            throw new RuntimeException('This call attempt is no longer answerable.');
        }

        return DB::transaction(function () use ($attempt, $operator, $operatorAttempt) {
            $caller = User::query()->findOrFail($attempt->citizen_id);
            $incident = $attempt->incident_id
                ? Incident::query()->findOrFail($attempt->incident_id)
                : null;

            if ($incident && (int) $incident->operator_id !== (int) $operator->id) {
                throw new RuntimeException('You cannot answer this routed call.');
            }

            if (! $incident) {
                $alertLevel = $this->settings->currentAlertLevel();

                $incident = Incident::query()->create([
                    'caller_id' => $caller->id,
                    'citizen_id' => $caller->id,
                    'actual_caller_name' => $caller->name,
                    'actual_caller_relationship' => 'Self',
                    'operator_id' => $operator->id,
                    'status' => IncidentStatus::Active,
                    'alert_level' => $alertLevel,
                    'latitude' => $attempt->caller_latitude,
                    'longitude' => $attempt->caller_longitude,
                    'called_at' => now(),
                ]);
            }

            $attempt->forceFill([
                'incident_id' => $incident->id,
                'answered_by_operator_id' => $operator->id,
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::Answered,
                'ended_at' => now(),
            ])->save();

            $operatorAttempt->forceFill([
                'status' => CallStatus::Ended,
                'outcome' => CallOutcome::Answered,
                'answered_at' => now(),
                'ended_at' => now(),
            ])->save();

            $attempt->operatorAttempts()
                ->whereKeyNot($operatorAttempt->id)
                ->where('status', CallStatus::Calling)
                ->update([
                    'status' => CallStatus::Ended,
                    'outcome' => CallOutcome::TimedOut,
                    'ended_at' => now(),
                ]);

            $callSession = CallSession::query()->create([
                'incident_id' => $incident->id,
                'caller_id' => $caller->id,
                'citizen_id' => $caller->id,
                'status' => CallStatus::InProgress,
                'started_at' => $attempt->started_at ?? now(),
                'answered_at' => null,
            ]);

            CallParticipant::query()->insert([
                [
                    'call_session_id' => $callSession->id,
                    'user_id' => $caller->id,
                    'participant_role' => 'citizen',
                    'joined_at' => now(),
                    'left_at' => null,
                    'created_at' => now(),
                ],
                [
                    'call_session_id' => $callSession->id,
                    'user_id' => $operator->id,
                    'participant_role' => 'operator',
                    'joined_at' => now(),
                    'left_at' => null,
                    'created_at' => now(),
                ],
            ]);

            return [
                'attempt' => $attempt->fresh(),
                'incident' => $incident->fresh(['caller', 'operator', 'callSessions.participants']),
                'call_session' => $callSession->fresh(['participants']),
            ];
        });
    }
}
