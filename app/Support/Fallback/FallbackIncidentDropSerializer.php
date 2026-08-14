<?php

namespace App\Support\Fallback;

use App\Domain\Fallback\Models\FallbackIncidentDrop;
use App\Domain\Fallback\Models\FallbackIncidentDropAttachment;
use App\Domain\Fallback\Models\FallbackIncidentDropHistory;

class FallbackIncidentDropSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(FallbackIncidentDrop $drop): array
    {
        $drop->loadMissing(['citizen:id,name,mobile,email', 'claimedByOperator:id,name', 'convertedIncident:id', 'attachments', 'histories.actor:id,name']);

        return [
            'id' => (int) $drop->id,
            'display_id' => str_pad((string) $drop->id, 6, '0', STR_PAD_LEFT),
            'status' => $drop->status,
            'reason' => $drop->reason,
            'quick_category' => $drop->quick_category,
            'short_description' => $drop->short_description,
            'citizen_location' => [
                'latitude' => $drop->citizen_latitude,
                'longitude' => $drop->citizen_longitude,
                'accuracy' => $drop->citizen_location_accuracy,
            ],
            'citizen' => $drop->citizen ? [
                'id' => (int) $drop->citizen->id,
                'name' => $drop->citizen->name,
                'mobile' => $drop->citizen->mobile,
            ] : null,
            'claimed_by_operator' => $drop->claimedByOperator ? [
                'id' => (int) $drop->claimedByOperator->id,
                'name' => $drop->claimedByOperator->name,
            ] : null,
            'converted_incident_id' => $drop->converted_incident_id ? (int) $drop->converted_incident_id : null,
            'closure_disposition' => $drop->closure_disposition,
            'closure_note' => $drop->closure_note,
            'claimed_at' => $drop->claimed_at?->toIso8601String(),
            'converted_at' => $drop->converted_at?->toIso8601String(),
            'closed_at' => $drop->closed_at?->toIso8601String(),
            'created_at' => $drop->created_at?->toIso8601String(),
            'updated_at' => $drop->updated_at?->toIso8601String(),
            'attachments' => $drop->attachments
                ->map(fn (FallbackIncidentDropAttachment $attachment) => $this->serializeAttachment($attachment))
                ->values()
                ->all(),
            'history' => $drop->histories
                ->sortBy('created_at')
                ->map(fn (FallbackIncidentDropHistory $history) => [
                    'id' => (int) $history->id,
                    'event' => $history->event,
                    'from_status' => $history->from_status,
                    'to_status' => $history->to_status,
                    'note' => $history->note,
                    'metadata' => $history->metadata ?? [],
                    'actor' => $history->actor ? [
                        'id' => (int) $history->actor->id,
                        'name' => $history->actor->name,
                    ] : null,
                    'created_at' => $history->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(FallbackIncidentDropAttachment $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'type' => $attachment->type,
            'original_filename' => $attachment->original_filename,
            'original_mime_type' => $attachment->original_mime_type,
            'stored_mime_type' => $attachment->stored_mime_type,
            'stored_filename' => $attachment->stored_filename,
            'stored_size_bytes' => $attachment->stored_size_bytes,
            'image_width' => $attachment->image_width,
            'image_height' => $attachment->image_height,
            'sha256' => $attachment->sha256,
            'normalized_at' => $attachment->normalized_at?->toIso8601String(),
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }
}
