<?php

namespace App\Http\Controllers\Api\Operator;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MediaLogController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event' => ['required', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
        ]);

        Log::warning('Hotline operator media pipeline event.', [
            'event' => (string) $validated['event'],
            'payload' => $validated['payload'] ?? [],
            'operator_id' => (int) $request->user()->id,
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }
}
