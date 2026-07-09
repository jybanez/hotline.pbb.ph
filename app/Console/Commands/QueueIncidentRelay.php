<?php

namespace App\Console\Commands;

use App\Domain\Incidents\Models\Incident;
use App\Support\IncidentRelay\IncidentRelayOutboxService;
use Illuminate\Console\Command;

class QueueIncidentRelay extends Command
{
    protected $signature = 'app:queue-incident-relay {incident : Incident id to queue for Relay handoff}';

    protected $description = 'Queue one Hotline incident for V1 incident Relay handoff.';

    public function handle(IncidentRelayOutboxService $outbox): int
    {
        $incident = Incident::query()->find((int) $this->argument('incident'));

        if (! $incident instanceof Incident) {
            $this->error('Incident not found.');

            return self::FAILURE;
        }

        $row = $outbox->markPending($incident);

        $this->info(sprintf('Queued incident #%06d in incident_relay_outbox row %d.', $incident->id, $row->id));

        return self::SUCCESS;
    }
}
