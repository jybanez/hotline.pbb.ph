<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CsrfTokenController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $request->session()->regenerateToken();

        return response()->json([
            'ok' => true,
            'csrf_token' => $request->session()->token(),
        ]);
    }
}
