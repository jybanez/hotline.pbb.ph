<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Command\Models\CommandBroadcast;
use App\Http\Controllers\Controller;
use App\Support\Realtime\RealtimeAdmissionService;
use App\Support\Realtime\RealtimeEventPublishService;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CommunityStatusController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RealtimeAdmissionService $admissions,
    ) {
    }

    public function status(): JsonResponse
    {
        $alertLevel = $this->settings->currentAlertLevel();

        return response()->json([
            'namespace' => 'pbb.hotline.community.v1',
            'generated_at' => now()->toIso8601String(),
            'alert' => [
                'level' => $alertLevel->value,
                'description' => $alertLevel->description(),
                'room' => RealtimeEventPublishService::SETTINGS_ROOM,
            ],
            'broadcasts' => $this->activeCommunityBroadcasts(),
            'realtime' => [
                'admission_url' => url('/api/public/community-realtime'),
                'rooms' => [
                    RealtimeEventPublishService::SETTINGS_ROOM,
                    RealtimeEventPublishService::BROADCAST_ROOM,
                ],
                'event_types' => [
                    'hotline.alert_level.changed',
                    'hotline.broadcast.created',
                ],
            ],
        ]);
    }

    public function realtime(): JsonResponse
    {
        try {
            return response()->json($this->admissions->forPublicCommunity());
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeCommunityBroadcasts(): array
    {
        return CommandBroadcast::query()
            ->with('creator')
            ->where('audience', 'global')
            ->whereNotNull('published_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest('published_at')
            ->limit(50)
            ->get()
            ->filter(fn (CommandBroadcast $broadcast): bool => $this->isCommunityVisible($broadcast))
            ->values()
            ->map(fn (CommandBroadcast $broadcast): array => $this->serializeBroadcast($broadcast))
            ->all();
    }

    private function isCommunityVisible(CommandBroadcast $broadcast): bool
    {
        $roles = array_map(
            fn (mixed $role): string => strtolower(trim((string) $role)),
            $broadcast->target_roles_json ?? [],
        );

        if ($roles === []) {
            return false;
        }

        return count(array_intersect($roles, ['citizen', 'caller', 'public', 'community'])) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBroadcast(CommandBroadcast $broadcast): array
    {
        return [
            'id' => (string) $broadcast->id,
            'title' => $broadcast->title,
            'message' => $broadcast->message,
            'tone' => $broadcast->tone,
            'audience' => 'community',
            'target_roles' => $broadcast->target_roles_json ?? [],
            'published_at' => $broadcast->published_at?->toIso8601String(),
            'expires_at' => $broadcast->expires_at?->toIso8601String(),
            'created_by' => [
                'id' => $broadcast->creator?->id,
                'name' => $broadcast->creator?->name,
                'role' => $broadcast->creator?->role?->value ?? (string) $broadcast->creator?->role,
            ],
        ];
    }
}
