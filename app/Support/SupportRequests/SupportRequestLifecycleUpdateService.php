<?php

namespace App\Support\SupportRequests;

use App\Domain\SupportRequests\Models\SupportRequest;
use App\Domain\SupportRequests\Models\SupportRequestHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupportRequestLifecycleUpdateService
{
    /**
     * @return array<int, string>
     */
    public function supportOwnedStatuses(): array
    {
        return [
            SupportRequest::STATUS_RECEIVED,
            SupportRequest::STATUS_UNDER_REVIEW,
            SupportRequest::STATUS_ACCEPTED,
            SupportRequest::STATUS_REJECTED,
            SupportRequest::STATUS_ASSIGNED,
            SupportRequest::STATUS_EN_ROUTE,
            SupportRequest::STATUS_FULFILLED,
            SupportRequest::STATUS_CLOSED,
        ];
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    public function handle(array $update): array
    {
        $supportRequest = $this->findRequest($update['local_request_id'] ?? null, $update['correlation_id'] ?? null);

        if (! $supportRequest) {
            Log::warning('Support Request lifecycle update rejected for unknown request.', [
                'local_request_id' => $update['local_request_id'] ?? null,
                'correlation_id' => $update['correlation_id'] ?? null,
                'relay_message_id' => $update['relay_message_id'] ?? null,
                'update_id' => $update['update_id'] ?? null,
                'message_type' => $update['message_type'] ?? null,
            ]);

            return [
                'ok' => false,
                'status' => 'unknown_request',
                'message' => 'Support Request was not found for the supplied local_request_id or correlation_id.',
                'http_status' => 404,
            ];
        }

        if ($this->isDuplicate($supportRequest, $update)) {
            return [
                'ok' => true,
                'status' => 'duplicate',
                'message' => 'Support Request lifecycle update was already processed.',
                'http_status' => 200,
                'support_request' => $supportRequest->fresh(['histories']),
            ];
        }

        $occurredAt = Carbon::parse((string) $update['updated_at']);
        $status = (string) $update['status'];
        $payload = is_array($update['payload'] ?? null) ? $update['payload'] : [];

        $supportRequest = DB::transaction(function () use ($supportRequest, $update, $payload, $occurredAt, $status): SupportRequest {
            $supportRequest->forceFill([
                'status' => $status,
                'support_request_id' => $this->scalarOrNull($update['support_request_id'] ?? null) ?? $supportRequest->support_request_id,
            ])->save();

            $supportRequest->histories()->create([
                'event_type' => (string) $update['message_type'],
                'status' => $status,
                'relay_message_id' => $this->scalarOrNull($update['relay_message_id'] ?? null),
                'update_id' => $this->scalarOrNull($update['update_id'] ?? null),
                'support_request_external_id' => $this->scalarOrNull($update['support_request_id'] ?? null),
                'source_system' => $this->scalarOrNull($update['source_system'] ?? null),
                'actor_name' => $this->actorName($payload),
                'message' => $this->scalarOrNull($payload['message'] ?? null),
                'payload_json' => [
                    'message_type' => $update['message_type'] ?? null,
                    'relay_message_id' => $update['relay_message_id'] ?? null,
                    'payload' => $payload,
                ],
                'occurred_at' => $occurredAt,
            ]);

            return $supportRequest->fresh(['histories']) ?? $supportRequest;
        });

        return [
            'ok' => true,
            'status' => 'accepted',
            'message' => 'Support Request lifecycle update accepted.',
            'http_status' => 202,
            'support_request' => $supportRequest,
        ];
    }

    private function findRequest(mixed $localRequestId, mixed $correlationId): ?SupportRequest
    {
        $localRequestId = $this->scalarOrNull($localRequestId);
        $correlationId = $this->scalarOrNull($correlationId);

        if ($localRequestId !== null) {
            $request = SupportRequest::query()->where('local_request_id', $localRequestId)->first();

            if ($request) {
                return $request;
            }
        }

        if ($correlationId !== null) {
            return SupportRequest::query()->where('correlation_id', $correlationId)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function isDuplicate(SupportRequest $supportRequest, array $update): bool
    {
        $relayMessageId = $this->scalarOrNull($update['relay_message_id'] ?? null);
        $updateId = $this->scalarOrNull($update['update_id'] ?? null);

        return SupportRequestHistory::query()
            ->where('support_request_id', $supportRequest->id)
            ->where(function ($query) use ($relayMessageId, $updateId): void {
                if ($relayMessageId !== null) {
                    $query->orWhere('relay_message_id', $relayMessageId);
                }

                if ($updateId !== null) {
                    $query->orWhere('update_id', $updateId);
                }
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function actorName(array $payload): ?string
    {
        $updatedBy = is_array($payload['updated_by'] ?? null) ? $payload['updated_by'] : [];

        return $this->scalarOrNull($updatedBy['display_name'] ?? null)
            ?? $this->scalarOrNull($updatedBy['system'] ?? null);
    }

    private function scalarOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
