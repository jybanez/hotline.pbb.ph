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
    private const JUSTIFICATION_OPTIONS = [
        'local_resources_unavailable' => 'Local resources unavailable',
        'local_resources_insufficient' => 'Local resources insufficient',
        'specialized_capability_required' => 'Specialized capability required',
        'urgent_life_safety_need' => 'Urgent life-safety need',
        'access_or_route_support_required' => 'Access or route support required',
        'inter_agency_coordination_needed' => 'Inter-agency coordination needed',
        'sustained_operation_relief_rotation_needed' => 'Sustained operation / relief rotation needed',
        'verification_or_assessment_support_needed' => 'Verification or assessment support needed',
    ];

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
            'justification_codes' => ['required', 'array', 'min:1'],
            'justification_codes.*' => ['required', 'string', Rule::in(array_keys(self::JUSTIFICATION_OPTIONS))],
            'staging_notes' => ['nullable', 'string', 'max:2000'],
            'command_notes' => ['nullable', 'string', 'max:2000'],
            'gap' => ['nullable', 'array'],
            'evidence_row' => ['required', 'array'],
            'incident_refs' => ['nullable', 'array'],
            'selected_incident_ids' => ['nullable', 'array'],
            'selected_incident_ids.*' => ['integer'],
            'support_context' => ['nullable', 'array'],
        ]);

        $this->ensureRequestableContext($validated);
        $validated['selected_incident_ids'] = $this->selectedIncidentIds($validated);
        $validated['support_context'] = $this->supportContext($validated);
        $validated['justification_codes'] = array_values(array_unique($validated['justification_codes']));
        $validated['justification_labels'] = array_values(array_map(
            fn (string $code): string => self::JUSTIFICATION_OPTIONS[$code],
            $validated['justification_codes'],
        ));

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
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function selectedIncidentIds(array $validated): array
    {
        $row = is_array($validated['evidence_row'] ?? null) ? $validated['evidence_row'] : [];
        $evidenceIncidentIds = $this->intList($row['incident_ids'] ?? []);
        $selectedIncidentIds = $this->intList($validated['selected_incident_ids'] ?? []);

        if ($selectedIncidentIds === []) {
            return $evidenceIncidentIds;
        }

        $outsideEvidence = array_values(array_diff($selectedIncidentIds, $evidenceIncidentIds));

        if ($outsideEvidence !== []) {
            throw ValidationException::withMessages([
                'selected_incident_ids' => 'Selected incidents must come from the selected SITREP resource evidence row.',
            ]);
        }

        return $selectedIncidentIds;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function supportContext(array $validated): array
    {
        $context = is_array($validated['support_context'] ?? null) ? $validated['support_context'] : [];
        $row = is_array($validated['evidence_row'] ?? null) ? $validated['evidence_row'] : [];
        $selectedIncidentIds = $validated['selected_incident_ids'] ?? [];

        $context['resource'] = [
            'resource_type_id' => is_numeric($row['resource_type_id'] ?? null) ? (int) $row['resource_type_id'] : null,
            'resource_type_name' => is_scalar($row['resource_type_name'] ?? null) ? (string) $row['resource_type_name'] : null,
            'resource_type_category_id' => is_numeric($row['resource_type_category_id'] ?? null) ? (int) $row['resource_type_category_id'] : null,
            'resource_type_category_name' => is_scalar($row['resource_type_category_name'] ?? null) ? (string) $row['resource_type_category_name'] : null,
            'evidence_quantity' => is_numeric($row['quantity'] ?? $row['quantity_requested'] ?? null) ? (int) ($row['quantity'] ?? $row['quantity_requested']) : null,
            'requested_quantity' => is_numeric($validated['quantity'] ?? null) ? (int) $validated['quantity'] : null,
            'unit_label' => is_scalar($row['unit_label'] ?? null) ? (string) $row['unit_label'] : null,
        ];
        $context['evidence_scope']['incident_ids'] = $this->intList($row['incident_ids'] ?? []);
        $context['request_scope']['selected_incident_ids'] = $this->intList($selectedIncidentIds);
        $context['request_scope']['quantity_note'] = 'Quantity is manually set by Command and may not equal selected incident count.';

        return $context;
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
            'justification_codes' => $supportRequest->justification_codes ?? [],
            'justification_labels' => $supportRequest->justification_labels ?? [],
            'selected_incident_ids' => $supportRequest->selected_incident_ids_json ?? [],
            'support_context' => $supportRequest->support_context_json ?? [],
            'urgency' => $supportRequest->urgency,
            'requested_at' => $supportRequest->requested_at?->toIso8601String(),
        ];
    }
}
