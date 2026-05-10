<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Calls\Models\CallAttemptOperatorAttempt;
use App\Http\Controllers\Controller;
use App\Support\Calls\CallRoutingService;
use App\Support\Incidents\IncidentPayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CallAttemptOperatorAttemptController extends Controller
{
    public function __construct(
        private readonly CallRoutingService $callRouting,
        private readonly IncidentPayloadBuilder $incidentPayloads,
    ) {
    }

    public function answer(Request $request, CallAttemptOperatorAttempt $attempt): JsonResponse
    {
        try {
            $result = $this->callRouting->answerNewAttempt($request->user(), $attempt);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $result['attempt'],
            'incident' => $this->incidentPayloads->buildWorkbenchPayload(
                $result['incident'],
                $request->user(),
                includeLegacyAliases: false,
            ),
            'call_session' => $result['call_session'],
        ]);
    }

    public function decline(Request $request, CallAttemptOperatorAttempt $attempt): JsonResponse
    {
        try {
            $callAttempt = $this->callRouting->declineNewAttempt($request->user(), $attempt);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $callAttempt,
        ]);
    }

    public function timeout(Request $request, CallAttemptOperatorAttempt $attempt): JsonResponse
    {
        try {
            $callAttempt = $this->callRouting->timeoutNewAttemptForOperator($request->user(), $attempt);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $callAttempt,
        ]);
    }

    public function cancelByCaller(Request $request, CallAttemptOperatorAttempt $attempt): JsonResponse
    {
        try {
            $callAttempt = $this->callRouting->cancelDirectedAttemptFromCaller($request->user(), $attempt);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'attempt' => $callAttempt,
        ]);
    }
}
