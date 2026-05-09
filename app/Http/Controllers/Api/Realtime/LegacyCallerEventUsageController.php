<?php

namespace App\Http\Controllers\Api\Realtime;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LegacyCallerEventUsageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surface' => ['required', 'string', Rule::in(['citizen', 'operator'])],
            'event_type' => ['required', 'string', 'max:120', 'starts_with:caller.'],
            'canonical_event_type' => ['nullable', 'string', 'max:120', 'starts_with:citizen.'],
            'room' => ['nullable', 'string', 'max:180'],
        ]);

        Log::info('Hotline legacy caller Realtime event used.', [
            'surface' => $validated['surface'],
            'event_type' => $validated['event_type'],
            'canonical_event_type' => $validated['canonical_event_type'] ?? null,
            'room' => $validated['room'] ?? null,
            'user_id' => $request->user()?->getKey(),
            'user_role' => $request->user()?->role?->value,
        ]);

        return response()->json([
            'logged' => true,
        ]);
    }
}
