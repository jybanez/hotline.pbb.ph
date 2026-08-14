<?php

namespace App\Http\Controllers\Api\Citizen;

use App\Domain\Fallback\Models\FallbackIncidentDrop;
use App\Http\Controllers\Controller;
use App\Support\Fallback\FallbackIncidentDropSerializer;
use App\Support\Fallback\FallbackIncidentDropService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FallbackIncidentDropController extends Controller
{
    public function __construct(
        private readonly FallbackIncidentDropService $drops,
        private readonly FallbackIncidentDropSerializer $serializer,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:all_operators_busy'],
            'citizen_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'citizen_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'citizen_location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'quick_category' => ['nullable', 'string', 'max:120'],
            'short_description' => ['required', 'string', 'min:5', 'max:1500'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
        ]);

        try {
            $drop = $this->drops->create(
                $request->user(),
                $validated,
                $request->file('photos', []),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'fallback_drop' => $this->serializer->serialize($drop),
        ], 201);
    }

    public function show(Request $request, FallbackIncidentDrop $fallbackDrop): JsonResponse
    {
        if ((int) $fallbackDrop->citizen_id !== (int) $request->user()->id) {
            abort(404);
        }

        return response()->json([
            'ok' => true,
            'fallback_drop' => $this->serializer->serialize($fallbackDrop),
        ]);
    }
}
