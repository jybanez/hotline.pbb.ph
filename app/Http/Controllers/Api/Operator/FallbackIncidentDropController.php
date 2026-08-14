<?php

namespace App\Http\Controllers\Api\Operator;

use App\Domain\Fallback\Models\FallbackIncidentDrop;
use App\Domain\Fallback\Models\FallbackIncidentDropAttachment;
use App\Http\Controllers\Controller;
use App\Support\Fallback\FallbackIncidentDropSerializer;
use App\Support\Fallback\FallbackIncidentDropService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
            ]);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        return response()->json([
            'ok' => true,
            'items' => $query->get()
                ->map(fn (FallbackIncidentDrop $drop) => $this->serializer->serialize($drop, includeOperatorAttachmentUrls: true))
                ->values()
                ->all(),
        ]);
    }

    public function claim(Request $request, FallbackIncidentDrop $fallbackDrop): JsonResponse
    {
        return $this->transition(fn () => $this->drops->claim($request->user(), $fallbackDrop));
    }

    public function attachment(
        Request $request,
        FallbackIncidentDrop $fallbackDrop,
        FallbackIncidentDropAttachment $attachment,
    ): BinaryFileResponse {
        if ((int) $attachment->fallback_incident_drop_id !== (int) $fallbackDrop->id) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($attachment->stored_path)) {
            abort(404);
        }

        $filename = $attachment->stored_filename ?: ('fallback-drop-' . $attachment->id . '.webp');
        $headers = [
            'Content-Type' => $attachment->stored_mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->boolean('download')) {
            return response()->download($disk->path($attachment->stored_path), $filename, $headers);
        }

        return response()->file($disk->path($attachment->stored_path), $headers);
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
            'fallback_drop' => $this->serializer->serialize($drop, includeOperatorAttachmentUrls: true),
        ]);
    }
}
