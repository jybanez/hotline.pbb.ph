<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Calls\Models\CallSession;
use App\Http\Controllers\Controller;
use App\Support\Calls\CallSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CallSessionController extends Controller
{
    public function __construct(
        private readonly CallSessionService $callSessions,
    ) {
    }

    public function answer(Request $request, CallSession $callSession): JsonResponse
    {
        try {
            $callSession = $this->callSessions->answerReconnect($request->user(), $callSession);
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

    public function ready(Request $request, CallSession $callSession): JsonResponse
    {
        try {
            $callSession = $this->callSessions->markReady(
                $request->user(),
                $callSession,
                $request->string('answered_at')->toString() ?: null,
            );
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
            $callSession = $this->callSessions->endActiveOperatorSession($request->user(), $callSession);
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

    public function citizenDisconnect(Request $request, CallSession $callSession): JsonResponse
    {
        try {
            $callSession = $this->callSessions->endActiveCitizenDisconnectedSession($request->user(), $callSession);
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
