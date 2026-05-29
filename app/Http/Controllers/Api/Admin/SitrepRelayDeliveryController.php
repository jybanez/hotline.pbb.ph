<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Sitreps\Models\SitrepRelayDelivery;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Http\Controllers\Controller;
use App\Jobs\SubmitSitrepRelayDelivery;
use Illuminate\Http\JsonResponse;

class SitrepRelayDeliveryController extends Controller
{
    public function index(): JsonResponse
    {
        $latestSitrepId = $this->latestSitrepId();
        $deliveries = SitrepRelayDelivery::query()
            ->with('sitrepReport')
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'items' => $deliveries
                ->map(fn (SitrepRelayDelivery $delivery): array => $this->serialize($delivery, $latestSitrepId))
                ->values(),
            'latest_sitrep_id' => $latestSitrepId,
        ]);
    }

    public function retry(SitrepRelayDelivery $delivery): JsonResponse
    {
        $latestSitrepId = $this->latestSitrepId();

        if ((int) $delivery->sitrep_report_id !== (int) $latestSitrepId) {
            return response()->json([
                'message' => 'This SITREP has been intentionally superseded by a newer report and is not relay-eligible.',
            ], 409);
        }

        if ($delivery->status === SitrepRelayDelivery::STATUS_SENT) {
            return response()->json([
                'message' => 'This SITREP has already been accepted by Relay.',
            ], 409);
        }

        $delivery->forceFill([
            'status' => SitrepRelayDelivery::STATUS_PENDING,
            'last_error' => null,
        ])->save();

        SubmitSitrepRelayDelivery::dispatch($delivery->id);

        return response()->json([
            'ok' => true,
            'delivery' => $this->serialize($delivery->refresh()->load('sitrepReport'), $latestSitrepId),
        ]);
    }

    private function latestSitrepId(): ?int
    {
        $id = SitrepReport::query()
            ->latest('generated_at')
            ->latest('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SitrepRelayDelivery $delivery, ?int $latestSitrepId): array
    {
        $sitrep = $delivery->sitrepReport;
        $isCurrent = (int) $delivery->sitrep_report_id === (int) $latestSitrepId;
        $displayStatus = $isCurrent ? $delivery->status : 'superseded';

        return [
            'id' => $delivery->id,
            'sitrep_report_id' => $delivery->sitrep_report_id,
            'status' => $delivery->status,
            'display_status' => $displayStatus,
            'status_note' => $isCurrent
                ? 'Current latest SITREP is relay-eligible until Relay accepts it.'
                : 'Intentionally not retried because a newer SITREP has superseded this report.',
            'is_current' => $isCurrent,
            'is_retryable' => $isCurrent && $delivery->status !== SitrepRelayDelivery::STATUS_SENT,
            'attempt_count' => $delivery->attempt_count,
            'relay_id' => $delivery->relay_id,
            'relay_message_id' => $delivery->relay_message_id,
            'deliveries_count' => $delivery->deliveries_count,
            'last_error' => $delivery->last_error,
            'last_attempted_at' => $delivery->last_attempted_at?->toIso8601String(),
            'submitted_at' => $delivery->submitted_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
            'sitrep' => $sitrep ? [
                'id' => $sitrep->id,
                'sequence_number' => $sitrep->sequence_number,
                'title' => $sitrep->title,
                'coverage_area' => $sitrep->coverage_area,
                'generated_at' => $sitrep->generated_at?->toIso8601String(),
                'alert_level' => $sitrep->alert_level,
                'status' => $sitrep->status,
                'visibility' => $sitrep->visibility,
            ] : null,
        ];
    }
}
