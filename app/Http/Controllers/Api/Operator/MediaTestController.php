<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Media\Models\Media;
use App\Http\Controllers\Controller;
use App\Support\Media\MediaAssemblyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MediaTestController extends Controller
{
    public function __construct(private readonly MediaAssemblyService $mediaAssembly)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mime_type' => ['nullable', 'string', 'max:120'],
            'extension' => ['nullable', 'string', 'max:12'],
            'track_kind' => ['nullable', 'string', 'max:40'],
            'segment_key' => ['nullable', 'string', 'max:120'],
            'started_at' => ['nullable', 'date'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'metadata' => ['nullable', 'array'],
        ]);

        /** @var \App\Models\User $operator */
        $operator = $request->user();

        $media = $this->mediaAssembly->createDiagnosticProcessingAsset($operator, [
            ...$validated,
            'track_kind' => $validated['track_kind'] ?? 'audio',
            'metadata' => [
                ...(Arr::get($validated, 'metadata', []) ?: []),
                'tool' => 'operator_media_stream_test_tool',
            ],
        ]);

        return response()->json([
            'media' => $this->serializeMedia($media),
        ], 201);
    }

    public function storeChunk(Request $request, Media $media): JsonResponse
    {
        $this->authorizeDiagnosticMedia($request, $media);

        $validated = $request->validate([
            'chunk' => ['required', 'file', 'max:51200'],
            'chunk_index' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        try {
            $result = $this->mediaAssembly->storeChunk(
                $media,
                (string) file_get_contents($validated['chunk']->getRealPath()),
                (int) $validated['chunk_index'],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'chunk' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'media' => $this->serializeMedia($media->fresh()),
            'chunk' => [
                'chunk_index' => (int) $validated['chunk_index'],
                'chunk_count' => $result['chunk_count'],
            ],
        ]);
    }

    public function finalize(Request $request, Media $media): JsonResponse
    {
        $this->authorizeDiagnosticMedia($request, $media);

        $validated = $request->validate([
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'ended_at' => ['nullable', 'date'],
            'extension' => ['nullable', 'string', 'max:12'],
        ]);

        try {
            $media = $this->mediaAssembly->finalizeProcessingAsset($media, $validated);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'media' => [$exception->getMessage()],
            ]);
        }

        return response()->json([
            'media' => $this->serializeMedia($media),
        ]);
    }

    private function authorizeDiagnosticMedia(Request $request, Media $media): void
    {
        $metadata = is_array($media->metadata_json) ? $media->metadata_json : [];

        if (
            ! (bool) Arr::get($metadata, 'diagnostic', false)
            || Arr::get($metadata, 'diagnostic_type') !== 'operator_media_stream_storage'
            || (int) $media->peer_user_id !== (int) $request->user()?->id
        ) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMedia(Media $media): array
    {
        $metadata = is_array($media->metadata_json) ? $media->metadata_json : [];
        $path = trim((string) $media->path);

        return [
            'id' => $media->id,
            'type' => $media->type,
            'peer_user_id' => $media->peer_user_id,
            'peer_role' => $media->peer_role,
            'peer_label' => $media->peer_label,
            'duration_seconds' => $media->duration_seconds,
            'metadata' => $metadata,
            'processing' => (bool) Arr::get($metadata, 'processing', false) && $media->available_at === null,
            'discarded' => (bool) Arr::get($metadata, 'discarded', false),
            'created_at' => $media->created_at?->toIso8601String(),
            'available_at' => $media->available_at?->toIso8601String(),
            'path' => $path !== '' ? $path : null,
            'playback_url' => $path !== '' && Storage::disk('public')->exists($path)
                ? Storage::disk('public')->url($path)
                : null,
        ];
    }
}
