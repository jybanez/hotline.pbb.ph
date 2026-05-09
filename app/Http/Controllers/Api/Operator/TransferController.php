<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentTransfer;
use App\Http\Controllers\Controller;
use App\Support\Incidents\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $transfers,
    ) {
    }

    public function store(Request $request, Incident $incident): JsonResponse
    {
        $validated = $request->validate([
            'to_operator_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $transfer = $this->transfers->request(
                $request->user(),
                $incident,
                (int) $validated['to_operator_id'],
                $validated['reason'],
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'transfer' => $transfer,
        ], 201);
    }

    public function accept(Request $request, IncidentTransfer $transfer): JsonResponse
    {
        try {
            $transfer = $this->transfers->accept($request->user(), $transfer);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'transfer' => $transfer,
        ]);
    }

    public function reject(Request $request, IncidentTransfer $transfer): JsonResponse
    {
        try {
            $transfer = $this->transfers->reject($request->user(), $transfer);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'transfer' => $transfer,
        ]);
    }
}
