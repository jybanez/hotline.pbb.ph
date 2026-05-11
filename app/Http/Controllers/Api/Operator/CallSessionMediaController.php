<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Calls\Models\CallSession;
use App\Domain\Media\Models\Media;
use App\Http\Controllers\Controller;
use App\Support\Media\MediaAssemblyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class CallSessionMediaController extends Controller
{
    public function __construct(
        private readonly MediaAssemblyService $mediaAssembly,
    ) {
    }

    public function store(Request $request, CallSession $callSession): JsonResponse
    {
        abort_unless($this->canAccessCallSession($request, $callSession), 404);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:audio_peer,citizen_video'],
            'peer_user_id' => ['nullable', 'integer'],
            'peer_role' => ['nullable', 'string', 'in:citizen,operator'],
            'peer_label' => ['nullable', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'extension' => ['nullable', 'string', 'max:16'],
            'track_kind' => ['nullable', 'string', 'in:audio,video'],
            'segment_key' => ['nullable', 'string', 'max:255'],
            'started_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ]);

        $media = $this->mediaAssembly->createProcessingAsset($callSession, $validated);

        return response()->json([
            'ok' => true,
            'media' => $this->serializeMedia($media),
        ], 201);
    }

    public function storeChunk(Request $request, Media $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($request, $media), 404);

        $validated = $request->validate([
            'chunk' => ['required', 'file', 'max:51200'],
            'chunk_index' => ['required', 'integer', 'min:0'],
        ]);

        /** @var UploadedFile $chunk */
        $chunk = $validated['chunk'];

        try {
            $result = $this->mediaAssembly->storeChunk(
                $media,
                file_get_contents($chunk->getRealPath()) ?: '',
                (int) $validated['chunk_index'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'chunk' => $result,
        ], 201);
    }

    public function finalize(Request $request, Media $media): JsonResponse
    {
        abort_unless($this->canAccessMedia($request, $media), 404);

        $validated = $request->validate([
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'ended_at' => ['nullable', 'date'],
            'extension' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            $media = $this->mediaAssembly->finalizeProcessingAsset($media, $validated);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'media' => $this->serializeMedia($media),
        ]);
    }

    private function canAccessCallSession(Request $request, CallSession $callSession): bool
    {
        $callSession->loadMissing('incident');
        $incident = $callSession->incident;

        return $incident && (int) $incident->operator_id === (int) $request->user()->id;
    }

    private function canAccessMedia(Request $request, Media $media): bool
    {
        $callSession = CallSession::query()->with('incident')->find($media->call_session_id);

        return $callSession ? $this->canAccessCallSession($request, $callSession) : false;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMedia(Media $media): array
    {
        return [
            'id' => $media->id,
            'incident_id' => $media->incident_id,
            'call_session_id' => $media->call_session_id,
            'type' => $media->type,
            'peer_user_id' => $media->peer_user_id,
            'peer_role' => $media->peer_role,
            'peer_label' => $media->peer_label,
            'path' => $media->available_at ? $media->path : null,
            'duration_seconds' => $media->duration_seconds,
            'metadata' => $media->metadata_json ?? [],
            'processing' => $media->available_at === null,
            'created_at' => $media->created_at?->toIso8601String(),
            'available_at' => $media->available_at?->toIso8601String(),
        ];
    }
}
