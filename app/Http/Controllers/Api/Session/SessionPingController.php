<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SessionPingController extends Controller
{
    public function show(): JsonResponse
    {
        $touchedAt = now()->toIso8601String();

        return response()->json([
            'ok' => true,
            'csrf_token' => csrf_token(),
            'touched_at' => $touchedAt,
            'data' => [
                'csrf_token' => csrf_token(),
                'touched_at' => $touchedAt,
            ],
        ]);
    }
}
