<?php

namespace App\Support\Citizen;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Users\Models\User;
use App\Support\Incidents\IncidentPayloadBuilder;
use App\Support\Sessions\AvailabilityService;
use App\Support\Settings\SettingsService;

class CitizenHomePayloadBuilder
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly SettingsService $settings,
        private readonly IncidentPayloadBuilder $incidentPayloads,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $citizen): array
    {
        $currentIncident = Incident::query()
            ->where('citizen_id', $citizen->id)
            ->whereIn('status', [IncidentStatus::Active, IncidentStatus::Deferred])
            ->latest('id')
            ->first();

        $recentIncidents = Incident::query()
            ->where('citizen_id', $citizen->id)
            ->when($currentIncident, fn ($query) => $query->where('id', '!=', $currentIncident->id))
            ->latest('id')
            ->take(10)
            ->get();

        return [
            'current_open_incident' => $currentIncident
                ? $this->incidentPayloads->buildWorkbenchPayload($currentIncident, $citizen, includeLegacyAliases: false)
                : null,
            'recent_incidents' => $this->incidentPayloads->buildHistoryList($recentIncidents),
            ...$this->incidentPayloads->buildWorkbenchLookups(),
            'availability' => $this->availability->callerAvailability(),
            'alert_level' => $this->settings->currentAlertLevel()->value,
            'call_hold_seconds' => (int) $this->settings->get('call_hold_seconds'),
        ];
    }
}
