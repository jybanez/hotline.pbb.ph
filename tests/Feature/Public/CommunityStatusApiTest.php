<?php

namespace Tests\Feature\Public;

use App\Domain\Command\Models\CommandBroadcast;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_community_status_returns_alert_and_community_broadcasts(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'alert_level'],
            ['value' => ['value' => AlertLevel::Elevated->value]],
        );

        $command = User::factory()->create([
            'role' => UserRole::Command,
        ]);

        CommandBroadcast::query()->create([
            'title' => 'Community Advisory',
            'message' => 'Evacuation center is open.',
            'tone' => 'warning',
            'audience' => 'global',
            'target_roles_json' => ['citizen'],
            'created_by_user_id' => $command->id,
            'published_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);

        CommandBroadcast::query()->create([
            'title' => 'Operator Only',
            'message' => 'Internal routing note.',
            'tone' => 'info',
            'audience' => 'global',
            'target_roles_json' => ['operator'],
            'created_by_user_id' => $command->id,
            'published_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson('/api/public/community-status')
            ->assertOk()
            ->assertJsonPath('namespace', 'pbb.hotline.community.v1')
            ->assertJsonPath('alert.level', 'Elevated')
            ->assertJsonPath('alert.description', AlertLevel::Elevated->description())
            ->assertJsonPath('broadcasts.0.title', 'Community Advisory')
            ->assertJsonPath('broadcasts.0.audience', 'community')
            ->assertJsonMissingPath('broadcasts.1')
            ->assertJsonPath('realtime.rooms.0', 'hotline.settings.global')
            ->assertJsonPath('realtime.rooms.1', 'hotline.broadcast.global');
    }

    public function test_public_community_realtime_returns_narrow_admission(): void
    {
        $this->storeRealtimeSettings();

        $this->getJson('/api/public/community-realtime')
            ->assertOk()
            ->assertJsonStructure(['token', 'websocket_url', 'app_code', 'project_code', 'rooms', 'expires_at', 'session'])
            ->assertJsonPath('app_code', 'clt_PUBLIC_COMMUNITY_TEST')
            ->assertJsonPath('project_code', 'prj_PUBLIC_CITIZEN_TEST')
            ->assertJsonPath('rooms.0', 'hotline.settings.global')
            ->assertJsonPath('rooms.1', 'hotline.broadcast.global')
            ->assertJsonPath('session.user_id', 'community')
            ->assertJsonPath('session.capabilities.0', 'session.connect')
            ->assertJsonPath('session.capabilities.1', 'room.join')
            ->assertJsonPath('websocket_url', 'wss://realtime-test.pbb.ph/realtime');
    }

    public function test_public_community_realtime_requires_signing_secret(): void
    {
        $this->getJson('/api/public/community-realtime')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Realtime token signing secret is not configured.');
    }

    private function storeRealtimeSettings(): void
    {
        foreach ([
            'realtime_client_code' => 'clt_PUBLIC_COMMUNITY_TEST',
            'realtime_project_code_caller' => 'prj_PUBLIC_CITIZEN_TEST',
            'realtime_url' => 'https://realtime-test.pbb.ph',
            'realtime_token_signing_secret' => 'community-secret-001',
        ] as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value]],
            );
        }
    }
}

