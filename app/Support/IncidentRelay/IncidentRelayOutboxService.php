<?php

namespace App\Support\IncidentRelay;

use App\Domain\IncidentRelay\Models\IncidentRelayOutbox;
use App\Domain\Incidents\Models\Incident;
use Illuminate\Support\Carbon;

class IncidentRelayOutboxService
{
    public function markPending(Incident|int $incident): IncidentRelayOutbox
    {
        $incidentId = $incident instanceof Incident ? $incident->id : $incident;
        $existing = IncidentRelayOutbox::query()->where('incident_id', $incidentId)->first();
        $now = now();

        if ($existing instanceof IncidentRelayOutbox) {
            $existing->forceFill([
                'message_type' => IncidentRelaySerializer::MESSAGE_TYPE,
                'status' => IncidentRelayOutbox::STATUS_PENDING,
                'pending_since' => $existing->pending_since ?? $now,
                'last_changed_at' => $now,
                'last_error' => null,
            ])->save();

            return $existing;
        }

        return IncidentRelayOutbox::query()->create([
            'incident_id' => $incidentId,
            'message_type' => IncidentRelaySerializer::MESSAGE_TYPE,
            'status' => IncidentRelayOutbox::STATUS_PENDING,
            'pending_since' => $now,
            'last_changed_at' => $now,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, IncidentRelayOutbox>
     */
    public function due(int $limit, int $debounceSeconds, bool $retryFailed = false, bool $force = false)
    {
        $query = IncidentRelayOutbox::query()
            ->with('incident')
            ->orderBy('last_changed_at')
            ->orderBy('id')
            ->limit($limit);

        $statuses = [IncidentRelayOutbox::STATUS_PENDING];

        if ($retryFailed) {
            $statuses[] = IncidentRelayOutbox::STATUS_FAILED;
        }

        $query->whereIn('status', $statuses);

        if (! $force) {
            $query->where(function ($query) use ($debounceSeconds): void {
                $query
                    ->whereNull('last_changed_at')
                    ->orWhere('last_changed_at', '<=', Carbon::now()->subSeconds($debounceSeconds));
            });
        }

        return $query->get();
    }
}
