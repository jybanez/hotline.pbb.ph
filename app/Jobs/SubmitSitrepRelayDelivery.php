<?php

namespace App\Jobs;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Support\Sitreps\SitrepRelaySubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitSitrepRelayDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly int $deliveryId,
    ) {
        $this->onQueue('default');
    }

    public function handle(SitrepRelaySubmissionService $submissions): void
    {
        $delivery = SitrepRelayDelivery::query()
            ->with('sitrepReport')
            ->find($this->deliveryId);

        if (! $delivery || $delivery->status === SitrepRelayDelivery::STATUS_SENT) {
            return;
        }

        $submissions->submit($delivery);
    }
}
