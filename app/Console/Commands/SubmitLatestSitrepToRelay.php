<?php

namespace App\Console\Commands;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Support\Sitreps\SitrepRelayOutboxService;
use App\Support\Sitreps\SitrepRelaySubmissionService;
use Illuminate\Console\Command;

class SubmitLatestSitrepToRelay extends Command
{
    protected $signature = 'app:submit-latest-sitrep-to-relay';

    protected $description = 'Submit the latest unsent SITREP to local Relay.';

    public function handle(SitrepRelayOutboxService $outbox, SitrepRelaySubmissionService $submissions): int
    {
        $delivery = $outbox->latestUnsentDelivery();

        if (! $delivery) {
            $this->info('No current unsent SITREP delivery is pending.');

            return self::SUCCESS;
        }

        $submissions->submit($delivery->load('sitrepReport'));
        $delivery->refresh();

        if ($delivery->status === SitrepRelayDelivery::STATUS_SENT) {
            $this->info(sprintf('Submitted SITREP delivery #%d to Relay.', $delivery->id));

            return self::SUCCESS;
        }

        $this->warn(sprintf('SITREP delivery #%d remains %s: %s', $delivery->id, $delivery->status, $delivery->last_error ?? 'No error available.'));

        return self::SUCCESS;
    }
}
