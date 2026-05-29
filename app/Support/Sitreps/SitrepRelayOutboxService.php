<?php

namespace App\Support\Sitreps;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Jobs\SubmitSitrepRelayDelivery;

class SitrepRelayOutboxService
{
    public function queue(SitrepReport $sitrep): SitrepRelayDelivery
    {
        $delivery = SitrepRelayDelivery::query()->firstOrCreate(
            ['sitrep_report_id' => $sitrep->id],
            ['status' => SitrepRelayDelivery::STATUS_PENDING],
        );

        SubmitSitrepRelayDelivery::dispatch($delivery->id);

        return $delivery;
    }

    public function latestUnsentDelivery(): ?SitrepRelayDelivery
    {
        $latestReport = SitrepReport::query()
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if (! $latestReport) {
            return null;
        }

        return SitrepRelayDelivery::query()
            ->where('sitrep_report_id', $latestReport->id)
            ->whereIn('status', [
                SitrepRelayDelivery::STATUS_PENDING,
                SitrepRelayDelivery::STATUS_FAILED,
            ])
            ->first();
    }
}
