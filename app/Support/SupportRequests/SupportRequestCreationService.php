<?php

namespace App\Support\SupportRequests;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Domain\Users\Models\User;
use App\Domain\SupportRequests\Models\SupportRequest;
use App\Support\Sitreps\SitrepPayloadSchema;
use App\Support\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportRequestCreationService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $requester = null): SupportRequest
    {
        $sitrep = $this->sitrep($attributes['sitrep_report_id'] ?? null);
        $sourceSnapshot = $this->sourceSnapshot($sitrep, $attributes);
        $localRequestId = $this->requestId();
        $requestedAt = $attributes['requested_at'] ?? now();

        $request = DB::transaction(function () use ($attributes, $requester, $sitrep, $sourceSnapshot, $localRequestId, $requestedAt): SupportRequest {
            $supportRequest = SupportRequest::query()->create([
                'local_request_id' => $localRequestId,
                'correlation_id' => $attributes['correlation_id'] ?? $localRequestId,
                'status' => SupportRequest::STATUS_REQUESTED,
                'relay_delivery_status' => SupportRequest::RELAY_PENDING,
                'urgency' => $this->clean($attributes['urgency'] ?? 'normal', 'normal'),
                'requested_assistance' => $this->required($attributes, 'requested_assistance'),
                'requested_capability' => $this->clean($attributes['requested_capability'] ?? null),
                'quantity' => $this->nullableInt($attributes['quantity'] ?? null),
                'quantity_unit' => $this->clean($attributes['quantity_unit'] ?? null),
                'justification_codes' => $this->stringList($attributes['justification_codes'] ?? []),
                'justification_labels' => $this->stringList($attributes['justification_labels'] ?? []),
                'staging_notes' => $this->clean($attributes['staging_notes'] ?? null),
                'command_notes' => $this->clean($attributes['command_notes'] ?? null),
                'requester_user_id' => $requester?->id,
                'requester_name' => $requester?->name,
                'requester_role' => $requester?->role,
                'source_system' => $this->sourceSystem(),
                'source_hub_id' => $this->clean($sourceSnapshot['hub_id'] ?? null),
                'source_relay_hub_id' => $this->clean($sourceSnapshot['relay_hub_id'] ?? null),
                'source_hub_name' => $this->clean($sourceSnapshot['name'] ?? null),
                'source_snapshot_json' => $sourceSnapshot,
                'sitrep_report_id' => $sitrep?->id,
                'sitrep_sequence_number' => $sitrep?->sequence_number,
                'sitrep_generated_at' => $sitrep?->generated_at,
                'sitrep_section' => $this->clean($attributes['sitrep_section'] ?? null),
                'sitrep_evidence_ref' => $this->clean($attributes['sitrep_evidence_ref'] ?? null),
                'gap_json' => $this->arrayOrNull($attributes['gap'] ?? null),
                'evidence_row_json' => $this->arrayOrNull($attributes['evidence_row'] ?? null),
                'incident_refs_json' => $this->arrayOrNull($attributes['incident_refs'] ?? null) ?? [],
                'selected_incident_ids_json' => $this->intList($attributes['selected_incident_ids'] ?? []),
                'support_context_json' => $this->arrayOrNull($attributes['support_context'] ?? null) ?? [],
                'requested_at' => $requestedAt,
            ]);

            $supportRequest->histories()->create([
                'event_type' => 'support.request.created',
                'status' => SupportRequest::STATUS_REQUESTED,
                'source_system' => $supportRequest->source_system,
                'actor_name' => $supportRequest->requester_name,
                'message' => 'Command created an explicit Support Request.',
                'payload_json' => [
                    'local_request_id' => $supportRequest->local_request_id,
                    'correlation_id' => $supportRequest->correlation_id,
                    'selected_incident_ids' => $supportRequest->selected_incident_ids_json ?? [],
                ],
                'occurred_at' => $requestedAt,
            ]);

            return $supportRequest;
        });

        return $request->fresh(['histories']) ?? $request;
    }

    private function sourceSystem(): string
    {
        $sourceSystem = trim((string) $this->settings->get('support_request_relay_source_system', 'hotline.command'));

        return $sourceSystem !== '' ? $sourceSystem : 'hotline.command';
    }

    private function requestId(): string
    {
        return 'srq_'.strtolower((string) Str::ulid());
    }

    private function sitrep(mixed $id): ?SitrepReport
    {
        if (! is_numeric($id)) {
            return null;
        }

        return SitrepReport::query()->find((int) $id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sourceSnapshot(?SitrepReport $sitrep, array $attributes): array
    {
        if (is_array($attributes['source_snapshot'] ?? null)) {
            return $attributes['source_snapshot'];
        }

        if (! $sitrep) {
            return [];
        }

        $sourceSnapshot = SitrepPayloadSchema::rollup($sitrep->source_snapshot_json ?? []);
        $hubNode = $sourceSnapshot['hub_node'] ?? [];
        $snapshot = is_array($hubNode) && is_array($hubNode['snapshot'] ?? null)
            ? $hubNode['snapshot']
            : [];

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function required(array $attributes, string $key): string
    {
        $value = $this->clean($attributes[$key] ?? null);

        if ($value === null || $value === '') {
            throw new \InvalidArgumentException(sprintf('%s is required.', $key));
        }

        return $value;
    }

    private function clean(mixed $value, ?string $default = null): ?string
    {
        if (! is_scalar($value)) {
            return $default;
        }

        $clean = trim((string) $value);

        return $clean !== '' ? $clean : $default;
    }

    private function nullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @return array<mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_unique(array_map(
            fn (mixed $item): string => trim(is_scalar($item) ? (string) $item : ''),
            $value,
        )), fn (string $item): bool => $item !== ''));
    }

    /**
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?int => is_numeric($item) ? (int) $item : null,
            $value,
        ), fn (?int $item): bool => $item !== null)));
    }
}
