<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Support\Bootstrap\BootstrapPayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __construct(
        private readonly BootstrapPayloadBuilder $bootstrapPayloadBuilder,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(array_merge(
            $this->bootstrapPayloadBuilder->build($request->user(), $request->query('surface')),
            [
                'csrf_token' => $request->session()->token(),
                'session_lifetime_minutes' => (int) config('session.lifetime', 120),
                'session_touched_at' => now()->toIso8601String(),
            ],
        ));
    }
}
