<?php

namespace App\Http\Controllers\Api\Realtime;

use App\Http\Controllers\Controller;
use App\Support\Realtime\RealtimeAdmissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdmissionController extends Controller
{
    public function __construct(
        private readonly RealtimeAdmissionService $admissions,
    ) {
    }

    public function citizen(Request $request): JsonResponse
    {
        return $this->respond(
            request: $request,
            resolver: fn (string $contextType, int $contextId) => $this->admissions->forCitizen(
                $request->user(),
                $contextType,
                $contextId,
            ),
        );
    }

    public function caller(Request $request): JsonResponse
    {
        return $this->citizen($request);
    }

    public function operator(Request $request): JsonResponse
    {
        return $this->respond(
            request: $request,
            resolver: fn (string $contextType, int $contextId) => $this->admissions->forOperator(
                $request->user(),
                $contextType,
                $contextId,
            ),
        );
    }

    public function command(Request $request): JsonResponse
    {
        return $this->respond(
            request: $request,
            resolver: fn (string $contextType, int $contextId) => $this->admissions->forCommand(
                $request->user(),
                $contextType,
                $contextId,
            ),
        );
    }

    private function respond(Request $request, callable $resolver): JsonResponse
    {
        $validated = $request->validate([
            'context_type' => ['required', 'string', 'in:surface_runtime,settings_stream,incident_chat,call_session,media_ingest,call_discovery,dashboard_presence'],
            'context_id' => ['required', 'integer'],
        ]);

        try {
            return response()->json($resolver($validated['context_type'], (int) $validated['context_id']));
        } catch (AuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
