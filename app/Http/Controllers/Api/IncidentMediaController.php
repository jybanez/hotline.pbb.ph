<?php

namespace App\Http\Controllers\Api;

use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Support\Media\MediaContractNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentMediaController extends Controller
{
    public function index(Request $request, Incident $incident): JsonResponse
    {
        if (! $this->canAccessIncident($request, $incident)) {
            abort(404);
        }

        $isCaller = (int) $incident->citizen_id === (int) $request->user()->id;

        $items = $incident->mediaItems()
            ->when($isCaller, fn ($query) => $query->whereIn('type', MediaContractNormalizer::citizenVideoTypes()))
            ->orderBy('created_at')
            ->get()
            ->map(fn ($media) => [
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
            ])
            ->values()
            ->all();

        return response()->json([
            'items' => $items,
        ]);
    }

    private function canAccessIncident(Request $request, Incident $incident): bool
    {
        $user = $request->user();

        return ((int) $incident->citizen_id === (int) $user->id)
            || ((int) $incident->operator_id === (int) $user->id);
    }
}
