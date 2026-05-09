<?php

namespace App\Support\Caller;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Users\Models\User;
use App\Support\Incidents\IncidentPayloadBuilder;
use App\Support\Sessions\AvailabilityService;
use App\Support\Settings\SettingsService;

class CallerHomePayloadBuilder
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly SettingsService $settings,
        private readonly IncidentPayloadBuilder $incidentPayloads,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $caller): array
    {
        $currentIncident = Incident::query()
            ->where('caller_id', $caller->id)
            ->whereIn('status', [IncidentStatus::Active, IncidentStatus::Deferred])
            ->latest('id')
            ->first();

        $recentIncidents = Incident::query()
            ->where('caller_id', $caller->id)
            ->when($currentIncident, fn ($query) => $query->where('id', '!=', $currentIncident->id))
            ->latest('id')
            ->take(10)
            ->get();

        return [
            'current_open_incident' => $currentIncident ? $this->incidentPayloads->buildWorkbenchPayload($currentIncident, $caller) : null,
            'recent_incidents' => $this->incidentPayloads->buildHistoryList($recentIncidents),
            ...$this->incidentPayloads->buildWorkbenchLookups(),
            'availability' => $this->availability->callerAvailability(),
            'alert_level' => $this->settings->currentAlertLevel()->value,
            'call_hold_seconds' => (int) $this->settings->get('call_hold_seconds'),
        ];
    }
}
