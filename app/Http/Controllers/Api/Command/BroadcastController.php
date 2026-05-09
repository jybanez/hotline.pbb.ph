<?php

namespace App\Http\Controllers\Api\Command;

use App\Domain\Command\Models\CommandBroadcast;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Realtime\RealtimeEventPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BroadcastController extends Controller
{
    public function __construct(
        private readonly RealtimeEventPublishService $realtime,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:3', 'max:2000'],
            'tone' => ['nullable', 'string', 'in:info,warning,urgent'],
            'audience' => ['nullable', 'string', 'in:global'],
            'target_roles' => ['required', 'array', 'min:1'],
            'target_roles.*' => ['required', 'string', 'in:citizen,caller,operator'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $publishedAt = now();
        $expiresAt = array_key_exists('expires_at', $validated)
            ? Carbon::parse($validated['expires_at'])
            : $publishedAt->copy()->addHours(2);

        $broadcast = CommandBroadcast::query()->create([
            'title' => $this->nullableTrim($validated['title'] ?? null),
            'message' => trim((string) $validated['message']),
            'tone' => $validated['tone'] ?? 'info',
            'audience' => $validated['audience'] ?? 'global',
            'target_roles_json' => array_values(array_unique($validated['target_roles'])),
            'created_by_user_id' => $user->id,
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
        ]);

        $broadcast->load('creator');

        $realtime = $this->realtime->publishCommandBroadcast($broadcast);

        $broadcast->forceFill([
            'realtime_status' => $realtime['status'] ?? null,
            'realtime_meta_json' => $realtime,
        ])->save();

        return response()->json([
            'ok' => true,
            'broadcast' => $this->serialize($broadcast),
            'realtime' => $realtime,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CommandBroadcast $broadcast): array
    {
        return [
            'id' => $broadcast->id,
            'title' => $broadcast->title,
            'message' => $broadcast->message,
            'tone' => $broadcast->tone,
            'audience' => $broadcast->audience,
            'target_roles' => $broadcast->target_roles_json ?? [],
            'created_by' => [
                'id' => $broadcast->creator?->id,
                'name' => $broadcast->creator?->name,
                'role' => $broadcast->creator?->role?->value ?? (string) $broadcast->creator?->role,
            ],
            'published_at' => $broadcast->published_at?->toIso8601String(),
            'expires_at' => $broadcast->expires_at?->toIso8601String(),
            'realtime_status' => $broadcast->realtime_status,
        ];
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
