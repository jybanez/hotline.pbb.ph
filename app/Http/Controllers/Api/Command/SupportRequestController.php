<?php

namespace App\Http\Controllers\Api\Command;

use App\Domain\SupportRequests\Models\SupportRequest;
use App\Http\Controllers\Controller;
use App\Support\SupportRequests\SupportRequestCreationService;
use App\Support\SupportRequests\SupportRequestRelaySubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportRequestController extends Controller
{
    public function __construct(
        private readonly SupportRequestCreationService $requests,
        private readonly SupportRequestRelaySubmissionService $relay,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sitrep_report_id' => ['required', 'integer', 'exists:sitrep_reports,id'],
            'sitrep_section' => ['nullable', 'string', Rule::in([
                'summary',
                'situation',
                'damage',
                'population',
                'actions',
                'needs',
                'gaps',
                'period_activity',
                'verification_notes',
                'footer',
            ])],
            'sitrep_evidence_ref' => ['nullable', 'string', 'max:255'],
            'urgency' => ['required', 'string', Rule::in(['normal', 'high', 'urgent', 'critical'])],
            'requested_assistance' => ['required', 'string', 'max:255'],
            'requested_capability' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'quantity_unit' => ['nullable', 'string', 'max:40'],
            'staging_notes' => ['nullable', 'string', 'max:2000'],
            'command_notes' => ['nullable', 'string', 'max:2000'],
            'gap' => ['nullable', 'array'],
            'evidence_row' => ['required', 'array'],
            'incident_refs' => ['nullable', 'array'],
        ]);

        $this->ensureRequestableContext($validated);

        $supportRequest = $this->requests->create($validated, $request->user());
        $supportRequest = $this->relay->submit($supportRequest);

        return response()->json([
            'ok' => $supportRequest->relay_delivery_status !== SupportRequest::RELAY_FAILED,
            'support_request' => $this->serialize($supportRequest->refresh()),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function ensureRequestableContext(array $validated): void
    {
        $gap = is_array($validated['gap'] ?? null) ? $validated['gap'] : [];
        $row = is_array($validated['evidence_row'] ?? null) ? $validated['evidence_row'] : [];

        if ($this->isExplicitlyNonRequestable($gap, $row)) {
            throw ValidationException::withMessages([
                'support_context' => 'This SITREP item is informational and cannot be submitted as a Support Request.',
            ]);
        }

        if (! $this->isResourceSupplyGap($gap)) {
            throw ValidationException::withMessages([
                'support_context' => 'Support Requests can only be created from the SITREP Resource supply gap.',
            ]);
        }

        $resourceTypeId = (int) ($row['resource_type_id'] ?? 0);
        if (($row['kind'] ?? null) !== 'resource_need'
            || $resourceTypeId <= 0
            || ! DB::table('resource_types')->where('id', $resourceTypeId)->exists()) {
            throw ValidationException::withMessages([
                'support_context' => 'Support Requests can only be created from canonical SITREP resource evidence tied to a configured resource type.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $gap
     */
    private function isResourceSupplyGap(array $gap): bool
    {
        return $this->text($gap['type'] ?? '') === 'open_needs';
    }

    /**
     * @param  array<string, mixed>  $gap
     * @param  array<string, mixed>  $row
     */
    private function isExplicitlyNonRequestable(array $gap, array $row): bool
    {
        $category = $this->text($gap['category'] ?? '');
        $type = $this->text($gap['type'] ?? '');
        $title = $this->text($gap['title'] ?? '');
        $rowText = $this->combinedText($row);

        if (str_contains($category, 'data confidence') || str_contains($category, 'data quality')) {
            return true;
        }

        if (in_array($type, ['counting_scope', 'counting_note', 'counting_notes', 'data_quality'], true)) {
            return true;
        }

        if (isset($row['population_signal']) || str_contains($title, 'population figures require verification')) {
            return true;
        }

        foreach (['closed', 'resolved', 'discarded', 'historical', 'not current pressure'] as $blocked) {
            if (str_contains($title, $blocked) || str_contains($rowText, $blocked)) {
                return true;
            }
        }

        return false;
    }

    private function text(mixed $value): string
    {
        return strtolower(trim(is_scalar($value) ? (string) $value : ''));
    }

    /**
     * @param  array<mixed>  $values
     */
    private function combinedText(array $values): string
    {
        $parts = [];

        array_walk_recursive($values, function (mixed $value) use (&$parts): void {
            if (is_scalar($value)) {
                $parts[] = strtolower((string) $value);
            }
        });

        return implode(' ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SupportRequest $supportRequest): array
    {
        return [
            'id' => $supportRequest->id,
            'local_request_id' => $supportRequest->local_request_id,
            'correlation_id' => $supportRequest->correlation_id,
            'support_request_id' => $supportRequest->support_request_id,
            'status' => $supportRequest->status,
            'relay_delivery_status' => $supportRequest->relay_delivery_status,
            'relay_id' => $supportRequest->relay_id,
            'relay_message_id' => $supportRequest->relay_message_id,
            'relay_deliveries_count' => $supportRequest->relay_deliveries_count,
            'relay_last_error' => $supportRequest->relay_last_error,
            'requested_assistance' => $supportRequest->requested_assistance,
            'urgency' => $supportRequest->urgency,
            'requested_at' => $supportRequest->requested_at?->toIso8601String(),
        ];
    }
}
