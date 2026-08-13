<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Fallback\Models\FallbackIncidentDrop;
use App\Http\Controllers\Controller;
use App\Support\Fallback\FallbackIncidentDropSerializer;
use App\Support\Fallback\FallbackIncidentDropService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FallbackIncidentDropController extends Controller
{
    public function __construct(
        private readonly FallbackIncidentDropService $drops,
        private readonly FallbackIncidentDropSerializer $serializer,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = trim((string) $request->query('status', 'open'));
        $query = FallbackIncidentDrop::query()
            ->with(['citizen:id,name,mobile,email', 'claimedByOperator:id,name', 'attachments', 'histories.actor:id,name'])
            ->latest('created_at')
            ->limit(50);

        if ($status === 'open') {
            $query->whereIn('status', [
                FallbackIncidentDropService::STATUS_NEW,
                FallbackIncidentDropService::STATUS_CLAIMED,
                FallbackIncidentDropService::STATUS_CALLBACK_PENDING,
            ]);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json([
            'ok' => true,
            'items' => $query->get()
                ->map(fn (FallbackIncidentDrop $drop) => $this->serializer->serialize($drop))
                ->values()
                ->all(),
        ]);
    }

    public function claim(Request $request, FallbackIncidentDrop $fallbackDrop): JsonResponse
    {
        return $this->transition(fn () => $this->drops->claim($request->user(), $fallbackDrop));
    }

    public function callbackAttempt(Request $request, FallbackIncidentDrop $fallbackDrop): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->transition(fn () => $this->drops->recordCallbackAttempt(
            $request->user(),
            $fallbackDrop,
            $validated['note'] ?? null,
        ));
    }

    public function convert(Request $request, FallbackIncidentDrop $fallbackDrop): JsonResponse
    {
        return $this->transition(fn () => $this->drops->convert($request->user(), $fallbackDrop));
    }

    public function close(Request $request, FallbackIncidentDrop $fallbackDrop): JsonResponse
    {
        $validated = $request->validate([
            'disposition' => ['required', 'string', 'in:duplicate,invalid,spam,unreachable,resolved_without_incident,other'],
            'note' => ['nullable', 'string', 'max:1500'],
        ]);

        return $this->transition(fn () => $this->drops->close(
            $request->user(),
            $fallbackDrop,
            $validated['disposition'],
            $validated['note'] ?? null,
        ));
    }

    /**
     * @param callable(): FallbackIncidentDrop $callback
     */
    private function transition(callable $callback): JsonResponse
    {
        try {
            $drop = $callback();
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'fallback_drop' => $this->serializer->serialize($drop),
        ]);
    }
}
