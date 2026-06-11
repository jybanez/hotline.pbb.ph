<?php

namespace App\Http\Controllers\Api\Command;

use App\Domain\SupportRequests\Models\SupportRequest;
use App\Http\Controllers\Controller;
use App\Support\SupportRequests\SupportRequestCreationService;
use App\Support\SupportRequests\SupportRequestRelaySubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'evidence_row' => ['nullable', 'array'],
            'incident_refs' => ['nullable', 'array'],
        ]);

        $supportRequest = $this->requests->create($validated, $request->user());
        $supportRequest = $this->relay->submit($supportRequest);

        return response()->json([
            'ok' => $supportRequest->relay_delivery_status !== SupportRequest::RELAY_FAILED,
            'support_request' => $this->serialize($supportRequest->refresh()),
        ], 201);
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
