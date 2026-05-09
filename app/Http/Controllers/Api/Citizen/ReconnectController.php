<?php

namespace App\Http\Controllers\Api\Citizen;

use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Support\Calls\CallSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReconnectController extends Controller
{
    public function __construct(
        private readonly CallSessionService $callSessions,
    ) {
    }

    public function store(Request $request, Incident $incident): JsonResponse
    {
        try {
            $callSession = $this->callSessions->startReconnect($request->user(), $incident);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'call_session' => $callSession,
        ], 201);
    }

    public function cancel(Request $request, CallSession $callSession): JsonResponse
    {
        try {
            $callSession = $this->callSessions->cancelUnansweredReconnect($request->user(), $callSession);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'call_session' => $callSession,
        ]);
    }

    public function hangup(Request $request, CallSession $callSession): JsonResponse
    {
        try {
            $callSession = $this->callSessions->endActiveCallerSession($request->user(), $callSession);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'call_session' => $callSession,
        ]);
    }
}
