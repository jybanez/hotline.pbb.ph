<?php

namespace App\Console\Commands;

use App\Domain\Incidents\Models\Incident;
use App\Support\IncidentRelay\IncidentRelaySerializer;
use Illuminate\Console\Command;

class ExportIncidentRelay extends Command
{
    protected $signature = 'app:export-incident-relay
        {incident : Incident id to export}
        {--pretty : Pretty-print the JSON output}';

    protected $description = 'Serialize one Hotline incident into the V1 Relay incident snapshot payload.';

    public function handle(IncidentRelaySerializer $serializer): int
    {
        $incident = Incident::query()->find((int) $this->argument('incident'));

        if (! $incident instanceof Incident) {
            $this->error('Incident not found.');

            return self::FAILURE;
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ((bool) $this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $this->line(json_encode($serializer->serialize($incident), $flags) ?: '{}');

        return self::SUCCESS;
    }
}
