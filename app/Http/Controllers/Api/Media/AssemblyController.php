<?php

namespace App\Http\Controllers\Api\Media;

use App\Http\Controllers\Controller;
use App\Support\Media\MediaAssemblyService;
use App\Support\Media\MediaContractNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssemblyController extends Controller
{
    public function __construct(
        private readonly MediaAssemblyService $mediaAssembly,
    ) {
    }

    public function complete(Request $request): JsonResponse
    {
        $workerToken = (string) config('services.media_assembly.token', '');

        abort_if($workerToken === '' || $request->header('X-Media-Assembly-Token') !== $workerToken, 403);

        $validated = $request->validate([
            'incident_id' => ['required', 'integer'],
            'call_session_id' => ['required', 'integer'],
            'type' => ['required', 'string', 'in:audio_peer,caller_video,citizen_video'],
            'peer_user_id' => ['nullable', 'integer'],
            'peer_role' => ['nullable', 'string'],
            'peer_label' => ['nullable', 'string'],
            'path' => ['required', 'string', 'max:2048'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ]);

        $media = $this->mediaAssembly->registerCompletedAsset(MediaContractNormalizer::normalizePayload($validated));

        return response()->json([
            'ok' => true,
            'media' => $media,
        ], 201);
    }
}
