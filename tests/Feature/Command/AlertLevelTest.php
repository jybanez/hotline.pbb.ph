<?php

namespace Tests\Feature\Command;

use App\Domain\Shared\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertLevelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_command_user_can_update_alert_level_and_publish_existing_realtime_event(): void
    {
        Http::fake([
            'https://realtime.pbb.ph/api/v1/events/publish' => Http::response([
                'service' => 'PBB Realtime',
                'status' => 'accepted',
                'data' => [
                    'publish_id' => 'pub_01COMMAND_ALERT',
                    'published' => true,
                ],
            ], 202),
        ]);

        $command = User::factory()->create([
            'role' => UserRole::Command,
        ]);

        Setting::query()->create([
            'key' => 'realtime_backend_ingress_secret',
            'value' => ['value' => 'backend-secret-001'],
        ]);

        $this->actingAs($command)
            ->postJson('/api/command/alert-level', [
                'alert_level' => 'Critical',
            ])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('previous_alert_level', 'Normal')
            ->assertJsonPath('alert_level', 'Critical')
            ->assertJsonPath('realtime.status', 'accepted');

        $this->assertDatabaseHas('settings', [
            'key' => 'alert_level',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://realtime.pbb.ph/api/v1/events/publish'
                && $request->hasHeader('X-Realtime-Backend-Secret', 'backend-secret-001')
                && $request['client_code'] === 'clt_01KMXFPRXCTHJAG10DMACJFMYB'
                && $request['project_code'] === 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6'
                && $request['room'] === 'hotline.settings.global'
                && $request['event_type'] === 'hotline.alert_level.changed'
                && $request['payload']['alert_level'] === 'Critical';
        });
    }

    public function test_command_alert_level_endpoint_rejects_invalid_level(): void
    {
        $command = User::factory()->create([
            'role' => UserRole::Command,
        ]);

        $this->actingAs($command)
            ->postJson('/api/command/alert-level', [
                'alert_level' => 'Severe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alert_level');
    }
}
