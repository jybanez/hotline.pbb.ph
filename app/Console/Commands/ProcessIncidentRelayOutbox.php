<?php

namespace App\Console\Commands;

use App\Domain\IncidentRelay\Models\IncidentRelayDelivery;
use App\Support\IncidentRelay\IncidentRelayOutboxService;
use App\Support\IncidentRelay\IncidentRelaySubmissionService;
use App\Support\Settings\SettingsService;
use Illuminate\Console\Command;

class ProcessIncidentRelayOutbox extends Command
{
    protected $signature = 'app:process-incident-relay-outbox
        {--limit=25 : Maximum pending outbox rows to process}
        {--force : Ignore debounce delay}
        {--retry-failed : Include failed outbox rows}';

    protected $description = 'Submit pending Hotline incident Relay outbox rows to PBB Relay.';

    public function handle(
        SettingsService $settings,
        IncidentRelayOutboxService $outbox,
        IncidentRelaySubmissionService $submission,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $debounceSeconds = max(0, (int) $settings->get('incident_relay_debounce_seconds', 10));
        $rows = $outbox->due(
            $limit,
            $debounceSeconds,
            (bool) $this->option('retry-failed'),
            (bool) $this->option('force'),
        );

        if ($rows->isEmpty()) {
            $this->info('No incident relay outbox rows are due.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $delivery = $submission->submit($row);

            if ($delivery->status === IncidentRelayDelivery::STATUS_SENT) {
                $sent++;
                $this->info(sprintf('Sent incident #%06d via Relay message %s.', $row->incident_id, $delivery->relay_message_id ?? 'n/a'));

                continue;
            }

            $failed++;
            $this->warn(sprintf('Failed incident #%06d: %s', $row->incident_id, $delivery->last_error ?? 'unknown error'));
        }

        $this->line(sprintf('Processed %d outbox row(s): %d sent, %d failed.', $rows->count(), $sent, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
