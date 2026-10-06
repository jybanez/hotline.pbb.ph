<?php

namespace App\Support\Calls;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Users\Models\User;
use RuntimeException;

class CallParticipantReservation
{
    public const CALLBACK_RING_SECONDS = 60;

    // Every creation/answer path acquires these before incident/attempt/route locks.
    public function lock(int $operatorId, int $citizenId): void
    {
        User::query()->whereIn('id', [$operatorId, $citizenId])->orderBy('id')->lockForUpdate()->get();
    }

    public function expire(int $operatorId, int $citizenId): void
    {
        CallAttempt::query()->where('callback', true)->where('status', CallStatus::Calling)
            ->where('started_at', '<=', now()->subSeconds(self::CALLBACK_RING_SECONDS))
            ->where(fn ($query) => $query->where('citizen_id', $citizenId)
                ->orWhereHas('incident', fn ($incident) => $incident->where('operator_id', $operatorId)))
            ->update(['status' => CallStatus::Ended, 'outcome' => 'timed_out', 'ended_at' => now()]);
    }

    public function assertAvailable(int $operatorId, int $citizenId, ?int $exceptAttemptId = null, bool $callbacksOnly = false): void
    {
        $pending = CallAttempt::query()->where('status', CallStatus::Calling)
            ->when($callbacksOnly, fn ($query) => $query->where('callback', true))
            ->when($exceptAttemptId, fn ($query) => $query->whereKeyNot($exceptAttemptId))
            ->where(fn ($query) => $query->where('citizen_id', $citizenId)
                ->orWhereHas('operatorAttempts', fn ($route) => $route->where('operator_id', $operatorId)->where('status', CallStatus::Calling))
                ->orWhere(fn ($callback) => $callback->where('callback', true)->whereHas('incident', fn ($incident) => $incident->where('operator_id', $operatorId))))
            ->exists();
        $active = CallSession::query()->whereIn('status', [CallStatus::Calling, CallStatus::InProgress])
            ->where(fn ($query) => $query->where('citizen_id', $citizenId)
                ->orWhereHas('participants', fn ($participant) => $participant->where('user_id', $operatorId)->whereNull('left_at')))
            ->exists();
        if ($pending || $active) {
            throw new RuntimeException('A participant already has an active or pending call.');
        }
    }
}
