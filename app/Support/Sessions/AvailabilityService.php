<?php

namespace App\Support\Sessions;

use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\OperatorRuntimeState;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Users\Models\User;
use App\Support\Settings\SettingsService;

class AvailabilityService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return array{status:string,service_reachable:bool,call_service_ready:bool,available_operator_count:int}
     */
    public function callerAvailability(): array
    {
        $availableOperatorCount = User::query()
            ->where('role', UserRole::Operator)
            ->where('status', UserStatus::Active)
            ->get()
            ->filter(fn (User $user) => $this->operatorRuntimeState($user) === OperatorRuntimeState::Available->value)
            ->count();

        $callServiceReady = true;

        return [
            'status' => $callServiceReady
                ? ($availableOperatorCount > 0 ? 'green' : 'yellow')
                : 'red',
            'service_reachable' => true,
            'call_service_ready' => $callServiceReady,
            'available_operator_count' => $availableOperatorCount,
        ];
    }

    public function operatorRuntimeState(?User $user): string
    {
        if ($user === null || $user->status !== UserStatus::Active) {
            return OperatorRuntimeState::Offline->value;
        }

        $hasActiveIncident = Incident::query()
            ->where('operator_id', $user->id)
            ->whereIn('status', [IncidentStatus::Active, IncidentStatus::Deferred])
            ->exists();

        if ($hasActiveIncident) {
            return OperatorRuntimeState::Engaged->value;
        }

        return OperatorRuntimeState::Available->value;
    }
}
