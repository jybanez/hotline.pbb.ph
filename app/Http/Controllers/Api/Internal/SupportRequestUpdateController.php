<?php

namespace App\Http\Controllers\Api\Internal;

use App\Domain\SupportRequests\Models\SupportRequest;
use App\Http\Controllers\Controller;
use App\Support\Settings\SettingsService;
use App\Support\SupportRequests\SupportRequestLifecycleUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupportRequestUpdateController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SupportRequestLifecycleUpdateService $updates,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid Support Request Relay handler token.',
            ], 401);
        }

        $update = $this->normalize($request);
        $validator = Validator::make($update, [
            'message_type' => ['required', 'string', 'max:120', 'starts_with:support.request.', Rule::notIn(['support.request'])],
            'relay_message_id' => ['nullable', 'string', 'max:64', 'required_without:update_id'],
            'update_id' => ['nullable', 'string', 'max:64', 'required_without:relay_message_id'],
            'schema_version' => ['required', 'integer', 'in:1'],
            'local_request_id' => ['nullable', 'string', 'max:64', 'required_without:correlation_id'],
            'correlation_id' => ['nullable', 'string', 'max:64', 'required_without:local_request_id'],
            'support_request_id' => ['nullable', 'string', 'max:64'],
            'status' => ['required', 'string', Rule::in($this->updates->supportOwnedStatuses())],
            'updated_at' => ['required', 'date'],
            'source_system' => ['nullable', 'string', 'max:80'],
            'payload' => ['required', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid Support Request lifecycle update payload.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $typeStatus = substr((string) $validated['message_type'], strlen('support.request.'));

        if ($typeStatus !== $validated['status']) {
            return response()->json([
                'ok' => false,
                'message' => 'Support Request update status does not match the Relay message type.',
                'errors' => [
                    'status' => ['Status must match the support.request.* message type suffix.'],
                ],
            ], 422);
        }

        $result = $this->updates->handle($validated);
        $supportRequest = $result['support_request'] ?? null;

        return response()->json(array_filter([
            'ok' => $result['ok'],
            'status' => $result['status'],
            'message' => $result['message'],
            'support_request' => $supportRequest instanceof SupportRequest ? $this->serialize($supportRequest) : null,
        ], static fn ($value) => $value !== null), (int) $result['http_status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(Request $request): array
    {
        $input = $request->all();
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];

        return [
            'message_type' => $input['message_type'] ?? $input['type'] ?? null,
            'relay_message_id' => $input['relay_message_id'] ?? $input['message_id'] ?? $input['id'] ?? null,
            'update_id' => $payload['update_id'] ?? $input['update_id'] ?? null,
            'schema_version' => $payload['schema_version'] ?? $input['schema_version'] ?? null,
            'local_request_id' => $payload['local_request_id']
                ?? $payload['hotline_request_id']
                ?? (is_array($payload['request'] ?? null) ? ($payload['request']['local_request_id'] ?? null) : null),
            'correlation_id' => $payload['correlation_id'] ?? $input['correlation_id'] ?? null,
            'support_request_id' => $payload['support_request_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'updated_at' => $payload['updated_at'] ?? $payload['update_time'] ?? null,
            'source_system' => $input['source_system'] ?? (is_array($payload['updated_by'] ?? null) ? ($payload['updated_by']['system'] ?? null) : null),
            'payload' => $payload,
        ];
    }

    private function isAuthorized(Request $request): bool
    {
        $provided = trim((string) (
            $request->header('X-Relay-Key', '')
            ?: $request->header('X-Relay-Token', '')
            ?: $request->bearerToken()
        ));
        $expected = trim((string) $this->settings->get('support_request_relay_handler_token', ''));

        return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
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
            'histories_count' => $supportRequest->histories()->count(),
        ];
    }
}
