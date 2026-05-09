<?php

namespace App\Support\Incidents;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentTransfer;
use App\Domain\Shared\Enums\OperatorRuntimeState;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Support\Sessions\AvailabilityService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferService
{
    public function __construct(
        private readonly AvailabilityService $availability,
    ) {
    }

    public function request(User $operator, Incident $incident, int $toOperatorId, string $reason): IncidentTransfer
    {
        if ((int) $incident->operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot transfer this incident.');
        }

        if ((int) $operator->id === $toOperatorId) {
            throw new RuntimeException('You cannot transfer an incident to yourself.');
        }

        $target = User::query()
            ->where('role', UserRole::Operator)
            ->find($toOperatorId);

        if (! $target) {
            throw new RuntimeException('Selected transfer target is invalid.');
        }

        if ($this->availability->operatorRuntimeState($target) !== OperatorRuntimeState::Available->value) {
            throw new RuntimeException('Selected transfer target is not currently available.');
        }

        $hasOpenRequest = IncidentTransfer::query()
            ->where('incident_id', $incident->id)
            ->where('status', 'requested')
            ->exists();

        if ($hasOpenRequest) {
            throw new RuntimeException('This incident already has a pending transfer request.');
        }

        return IncidentTransfer::query()->create([
            'incident_id' => $incident->id,
            'from_operator_id' => $operator->id,
            'to_operator_id' => $target->id,
            'reason' => $reason,
            'status' => 'requested',
            'requested_at' => now(),
        ]);
    }

    public function accept(User $operator, IncidentTransfer $transfer): IncidentTransfer
    {
        if ((int) $transfer->to_operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot accept this transfer.');
        }

        if ($transfer->status !== 'requested') {
            throw new RuntimeException('This transfer is no longer pending.');
        }

        return DB::transaction(function () use ($operator, $transfer) {
            $incident = Incident::query()->findOrFail($transfer->incident_id);

            $incident->forceFill([
                'operator_id' => $operator->id,
            ])->save();

            $transfer->forceFill([
                'status' => 'accepted',
                'accepted_at' => now(),
                'completed_at' => now(),
            ])->save();

            return $transfer->fresh();
        });
    }

    public function reject(User $operator, IncidentTransfer $transfer): IncidentTransfer
    {
        if ((int) $transfer->to_operator_id !== (int) $operator->id) {
            throw new RuntimeException('You cannot reject this transfer.');
        }

        if ($transfer->status !== 'requested') {
            throw new RuntimeException('This transfer is no longer pending.');
        }

        $transfer->forceFill([
            'status' => 'rejected',
            'rejected_at' => now(),
        ])->save();

        return $transfer->fresh();
    }
}
