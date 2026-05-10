<?php

namespace App\Http\Controllers\Api\Internal;

use App\Domain\Calls\Models\CallSession;
use App\Domain\Media\Models\Media;
use App\Domain\Shared\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\Media\MediaContractNormalizer;
use App\Support\Media\MediaAssemblyService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MediaChunkIngressController extends Controller
{
    public function __construct(
        private readonly MediaAssemblyService $mediaAssembly,
        private readonly SettingsService $settings,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid media ingest secret.',
            ], 401);
        }

        $request->replace($this->normalizePayload($request));
        $hasChunkFile = $request->hasFile('chunk');

        $validated = $request->validate([
            'incident_id' => ['required', 'integer', 'min:1'],
            'call_session_id' => ['required', 'integer', 'min:1'],
            'media_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string', 'in:audio_peer,caller_video,citizen_video'],
            'peer_user_id' => ['nullable', 'integer'],
            'peer_role' => ['nullable', 'string', 'in:citizen,caller,operator'],
            'track_kind' => ['required', 'string', 'in:audio,video'],
            'mime_type' => ['required', 'string', 'max:255'],
            'extension' => ['nullable', 'string', 'max:16'],
            'segment_key' => ['nullable', 'string', 'max:255'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'chunk_total' => ['nullable', 'integer', 'min:1'],
            'total_bytes' => ['nullable', 'integer', 'min:0'],
            'chunk_data' => [$hasChunkFile ? 'nullable' : 'required', 'string'],
            'chunk' => [$hasChunkFile ? 'required' : 'nullable', 'file'],
            'sender_user_id' => ['required', 'integer', 'min:1'],
            'project_code' => ['nullable', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:255'],
        ]);

        $validated = MediaContractNormalizer::normalizePayload($validated);

        $media = Media::query()->find($validated['media_id']);

        if (! $media) {
            return response()->json([
                'ok' => false,
                'message' => 'Media asset not found.',
            ], 404);
        }

        if ((int) $media->incident_id !== (int) $validated['incident_id'] || (int) $media->call_session_id !== (int) $validated['call_session_id']) {
            return response()->json([
                'ok' => false,
                'message' => 'Media ingest context mismatch.',
            ], 422);
        }

        if (! MediaContractNormalizer::typesMatch((string) $media->type, (string) $validated['type'])) {
            return response()->json([
                'ok' => false,
                'message' => 'Media ingest type mismatch.',
            ], 422);
        }

        $metadata = $media->metadata_json ?? [];
        $expectedSegmentKey = trim((string) ($metadata['segment_key'] ?? ''));
        $providedSegmentKey = trim((string) ($validated['segment_key'] ?? ''));

        if ($expectedSegmentKey !== '' && $providedSegmentKey !== '' && $expectedSegmentKey !== $providedSegmentKey) {
            return response()->json([
                'ok' => false,
                'message' => 'Media ingest segment mismatch.',
            ], 422);
        }

        $expectedProjectCode = trim((string) $this->settings->get('realtime_project_code_media_ingest', ''));
        $providedProjectCode = trim((string) ($validated['project_code'] ?? ''));

        if ($expectedProjectCode !== '' && $providedProjectCode !== '' && $expectedProjectCode !== $providedProjectCode) {
            return response()->json([
                'ok' => false,
                'message' => 'Media ingest project mismatch.',
            ], 403);
        }

        $expectedRoom = sprintf('call.session.%d', (int) $media->call_session_id);
        $providedRoom = trim((string) ($validated['room'] ?? ''));

        if ($providedRoom !== '' && $providedRoom !== $expectedRoom) {
            return response()->json([
                'ok' => false,
                'message' => 'Media ingest room mismatch.',
            ], 403);
        }

        $callSession = CallSession::query()
            ->with('participants')
            ->find($media->call_session_id);

        if (! $callSession || ! $callSession->participants->contains(function ($participant) use ($validated): bool {
            return (int) $participant->user_id === (int) $validated['sender_user_id']
                && (string) $participant->participant_role === UserRole::Operator->value;
        })) {
            return response()->json([
                'ok' => false,
                'message' => 'Media ingest sender is not allowed for this call session.',
            ], 403);
        }

        try {
            $result = $this->mediaAssembly->storeChunk(
                $media,
                $this->resolveChunkBytes($request, $validated),
                (int) $validated['chunk_index'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], str_contains($exception->getMessage(), 'Invalid media chunk payload') ? 422 : 409);
        }

        return response()->json([
            'ok' => true,
            'chunk' => $result,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(Request $request): array
    {
        $input = $request->all();
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : $input;
        $meta = is_array($input['meta'] ?? null) ? $input['meta'] : [];
        $sender = is_array($meta['sender'] ?? null) ? $meta['sender'] : [];

        return [
            ...$payload,
            'sender_user_id' => $payload['sender_user_id'] ?? $sender['user_id'] ?? $input['sender_user_id'] ?? null,
            'project_code' => $payload['project_code'] ?? $input['project_code'] ?? $sender['project_code'] ?? null,
            'room' => $payload['room'] ?? $input['room'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function resolveChunkBytes(Request $request, array $validated): string
    {
        if ($request->hasFile('chunk')) {
            $path = $request->file('chunk')?->getRealPath();
            $bytes = $path ? file_get_contents($path) : false;

            if (! is_string($bytes)) {
                throw new RuntimeException('Invalid media chunk payload.');
            }

            return $bytes;
        }

        return $this->decodeChunkData((string) ($validated['chunk_data'] ?? ''));
    }

    private function isAuthorized(Request $request): bool
    {
        $provided = trim((string) (
            $request->header('X-Hotline-Media-Ingest-Secret', '')
            ?: $request->header('X-Realtime-Media-Ingest-Secret', '')
        ));
        $expected = trim((string) $this->settings->get('realtime_media_ingest_secret', ''));

        if ($expected === '') {
            $expected = trim((string) $this->settings->get('realtime_backend_ingress_secret', ''));
        }

        return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
    }

    private function decodeChunkData(string $chunkData): string
    {
        $encoded = trim($chunkData);

        if (preg_match('/^data:[^;]+;base64,/', $encoded) === 1) {
            $encoded = (string) preg_replace('/^data:[^;]+;base64,/', '', $encoded);
        }

        $decoded = base64_decode($encoded, true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Invalid media chunk payload.');
        }

        return $decoded;
    }
}
