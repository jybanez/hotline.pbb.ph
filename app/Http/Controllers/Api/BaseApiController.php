<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseApiController extends Controller
{
    /**
     * @param  mixed  $data
     * @param  mixed  $meta
     */
    protected function ok($data = null, $meta = null, int $statusCode = 200, array $headers = []): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $data,
            'meta' => $meta,
            'error' => null,
        ], $statusCode, $headers);
    }

    /**
     * @param  mixed  $data
     * @param  mixed  $meta
     */
    protected function fail(string $error, int $statusCode = 400, $data = null, $meta = null): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => $data,
            'meta' => $meta,
            'error' => $error,
        ], $statusCode);
    }
}
