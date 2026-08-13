<?php

namespace App\Support\Fallback;

use App\Domain\Fallback\Models\FallbackIncidentDrop;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Users\Models\User;
use App\Support\Realtime\RealtimeEventPublishService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FallbackIncidentDropService
{
    public const STATUS_NEW = 'new';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_CALLBACK_PENDING = 'callback_pending';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_CLOSED = 'closed';

    public const REASON_ALL_OPERATORS_BUSY = 'all_operators_busy';

    public function __construct(
        private readonly FallbackIncidentDropAttachmentService $attachments,
        private readonly RealtimeEventPublishService $realtime,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, UploadedFile> $photos
     */
    public function create(User $citizen, array $data, array $photos = []): FallbackIncidentDrop
    {
        $activeExists = FallbackIncidentDrop::query()
            ->where('citizen_id', $citizen->id)
            ->whereIn('status', [self::STATUS_NEW, self::STATUS_CLAIMED, self::STATUS_CALLBACK_PENDING])
            ->exists();

        if ($activeExists) {
            throw new RuntimeException('You already have emergency details awaiting operator review.');
        }

        $drop = DB::transaction(function () use ($citizen, $data, $photos): FallbackIncidentDrop {
            $drop = FallbackIncidentDrop::query()->create([
                'citizen_id' => $citizen->id,
                'status' => self::STATUS_NEW,
                'reason' => self::REASON_ALL_OPERATORS_BUSY,
                'citizen_latitude' => $data['citizen_latitude'] ?? null,
                'citizen_longitude' => $data['citizen_longitude'] ?? null,
                'citizen_location_accuracy' => $data['citizen_location_accuracy'] ?? null,
                'quick_category' => $data['quick_category'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'callback_contact_snapshot' => [
                    'user_id' => (int) $citizen->id,
                    'name' => $citizen->name,
                    'mobile' => $citizen->mobile,
                    'email' => $citizen->email,
                ],
            ]);

            foreach ($photos as $photo) {
                $this->attachments->storeImage($drop, $photo);
            }

            $this->recordHistory($drop, null, 'created', null, self::STATUS_NEW, 'Citizen fallback drop created after all operators were busy.');

            return $drop->fresh(['citizen', 'attachments', 'histories']);
        });

        $this->publishDropEvent('created', $drop);

        return $drop;
    }

    public function claim(User $operator, FallbackIncidentDrop $drop): FallbackIncidentDrop
    {
        $drop = DB::transaction(function () use ($operator, $drop): FallbackIncidentDrop {
            /** @var FallbackIncidentDrop $locked */
            $locked = FallbackIncidentDrop::query()->whereKey($drop->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === self::STATUS_CLAIMED && (int) $locked->claimed_by_operator_id === (int) $operator->id) {
                return $locked->fresh(['citizen', 'claimedByOperator', 'attachments', 'histories']);
            }

            if ($locked->status !== self::STATUS_NEW) {
                throw new RuntimeException('This fallback drop is no longer available to claim.');
            }

            $locked->forceFill([
                'status' => self::STATUS_CLAIMED,
                'claimed_by_operator_id' => $operator->id,
                'claimed_at' => now(),
            ])->save();

            $this->recordHistory($locked, $operator, 'claimed', self::STATUS_NEW, self::STATUS_CLAIMED);

            return $locked->fresh(['citizen', 'claimedByOperator', 'attachments', 'histories']);
        });

        $this->publishDropEvent('claimed', $drop);

        return $drop;
    }

    public function recordCallbackAttempt(User $operator, FallbackIncidentDrop $drop, ?string $note = null): FallbackIncidentDrop
    {
        $drop = DB::transaction(function () use ($operator, $drop, $note): FallbackIncidentDrop {
            /** @var FallbackIncidentDrop $locked */
            $locked = FallbackIncidentDrop::query()->whereKey($drop->id)->lockForUpdate()->firstOrFail();
            $this->assertOperatorOwnsOpenDrop($operator, $locked);
            $from = $locked->status;

            $locked->forceFill([
                'status' => self::STATUS_CALLBACK_PENDING,
                'callback_attempted_at' => now(),
            ])->save();

            $this->recordHistory($locked, $operator, 'callback_attempted', $from, self::STATUS_CALLBACK_PENDING, $note);

            return $locked->fresh(['citizen', 'claimedByOperator', 'attachments', 'histories']);
        });

        $this->publishDropEvent('callback_attempted', $drop);

        return $drop;
    }

    public function convert(User $operator, FallbackIncidentDrop $drop): FallbackIncidentDrop
    {
        $drop = DB::transaction(function () use ($operator, $drop): FallbackIncidentDrop {
            /** @var FallbackIncidentDrop $locked */
            $locked = FallbackIncidentDrop::query()->with('citizen')->whereKey($drop->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === self::STATUS_CONVERTED && $locked->converted_incident_id) {
                return $locked->fresh(['citizen', 'claimedByOperator', 'convertedIncident', 'attachments', 'histories']);
            }

            if ($locked->status === self::STATUS_CLOSED) {
                throw new RuntimeException('Closed fallback drops cannot be converted.');
            }

            if ($locked->claimed_by_operator_id && (int) $locked->claimed_by_operator_id !== (int) $operator->id) {
                throw new RuntimeException('This fallback drop is claimed by another operator.');
            }

            $from = $locked->status;
            $incident = Incident::query()->create([
                'citizen_id' => $locked->citizen_id,
                'actual_citizen_name' => $locked->citizen?->name,
                'actual_citizen_relationship' => 'Self',
                'operator_id' => $operator->id,
                'status' => IncidentStatus::Active,
                'alert_level' => $this->settings->currentAlertLevel(),
                'latitude' => $locked->citizen_latitude,
                'longitude' => $locked->citizen_longitude,
                'citizen_location_accuracy' => $locked->citizen_location_accuracy,
                'other_details' => $this->incidentDetails($locked),
                'called_at' => $locked->created_at ?? now(),
            ]);

            $locked->forceFill([
                'status' => self::STATUS_CONVERTED,
                'claimed_by_operator_id' => $locked->claimed_by_operator_id ?: $operator->id,
                'claimed_at' => $locked->claimed_at ?: now(),
                'converted_incident_id' => $incident->id,
                'converted_at' => now(),
            ])->save();

            $this->recordHistory($locked, $operator, 'converted', $from, self::STATUS_CONVERTED, "Converted to incident #{$incident->id}.");

            return $locked->fresh(['citizen', 'claimedByOperator', 'convertedIncident', 'attachments', 'histories']);
        });

        $this->publishDropEvent('converted', $drop);

        return $drop;
    }

    public function close(User $operator, FallbackIncidentDrop $drop, string $disposition, ?string $note = null): FallbackIncidentDrop
    {
        $drop = DB::transaction(function () use ($operator, $drop, $disposition, $note): FallbackIncidentDrop {
            /** @var FallbackIncidentDrop $locked */
            $locked = FallbackIncidentDrop::query()->whereKey($drop->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === self::STATUS_CONVERTED) {
                throw new RuntimeException('Converted fallback drops cannot be closed.');
            }

            if ($locked->status === self::STATUS_CLOSED) {
                return $locked->fresh(['citizen', 'claimedByOperator', 'attachments', 'histories']);
            }

            if ($locked->claimed_by_operator_id && (int) $locked->claimed_by_operator_id !== (int) $operator->id) {
                throw new RuntimeException('This fallback drop is claimed by another operator.');
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => self::STATUS_CLOSED,
                'claimed_by_operator_id' => $locked->claimed_by_operator_id ?: $operator->id,
                'claimed_at' => $locked->claimed_at ?: now(),
                'closure_disposition' => $disposition,
                'closure_note' => $note,
                'closed_at' => now(),
            ])->save();

            $this->recordHistory($locked, $operator, 'closed', $from, self::STATUS_CLOSED, $note, [
                'disposition' => $disposition,
            ]);

            return $locked->fresh(['citizen', 'claimedByOperator', 'attachments', 'histories']);
        });

        $this->publishDropEvent('closed', $drop);

        return $drop;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordHistory(
        FallbackIncidentDrop $drop,
        ?User $actor,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $note = null,
        array $metadata = [],
    ): void {
        $drop->histories()->create([
            'actor_id' => $actor?->id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'note' => $note,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    private function assertOperatorOwnsOpenDrop(User $operator, FallbackIncidentDrop $drop): void
    {
        if (! in_array($drop->status, [self::STATUS_CLAIMED, self::STATUS_CALLBACK_PENDING], true)) {
            throw new RuntimeException('This fallback drop must be claimed before callback updates.');
        }

        if ((int) $drop->claimed_by_operator_id !== (int) $operator->id) {
            throw new RuntimeException('This fallback drop is claimed by another operator.');
        }
    }

    private function incidentDetails(FallbackIncidentDrop $drop): string
    {
        $parts = [
            'Converted from fallback incident drop #' . str_pad((string) $drop->id, 6, '0', STR_PAD_LEFT) . '.',
        ];

        if ($drop->quick_category) {
            $parts[] = 'Category: ' . $drop->quick_category;
        }

        if ($drop->short_description) {
            $parts[] = 'Citizen details: ' . $drop->short_description;
        }

        $parts[] = 'Fallback reason: all operators were busy; no call attempt was routed.';
        $parts[] = 'Photos attached to the fallback drop remain unverified and are not incident evidence until reviewed.';

        return implode("\n", $parts);
    }

    private function publishDropEvent(string $event, FallbackIncidentDrop $drop): void
    {
        try {
            $this->realtime->publishFallbackDropNotification(
                $event,
                [
                    'id' => (int) $drop->id,
                    'status' => $drop->status,
                    'reason' => $drop->reason,
                    'created_at' => $drop->created_at?->toIso8601String(),
                    'updated_at' => $drop->updated_at?->toIso8601String(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Fallback drop realtime notification failed.', [
                'fallback_drop_id' => $drop->id,
                'event' => $event,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
