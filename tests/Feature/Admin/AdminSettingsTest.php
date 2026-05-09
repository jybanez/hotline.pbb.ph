<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_view_and_update_runtime_and_integration_settings(): void
    {
        Http::fake([
            'https://realtime-beta.pbb.ph/api/v1/events/publish' => Http::response([
                'service' => 'PBB Realtime',
                'status' => 'accepted',
                'data' => [
                    'publish_id' => 'pub_01TEST',
                    'published' => true,
                ],
            ], 202),
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'realtime_client_code',
                'value' => 'clt_01KMXFPRXCTHJAG10DMACJFMYB',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_server',
                'value' => 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_caller',
                'value' => 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_operator',
                'value' => 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_media_ingest',
                'value' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_url',
                'value' => 'https://realtime.pbb.ph',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_backend_ingress_secret',
                'value' => '',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_token_signing_secret',
                'value' => '',
            ])
            ->assertJsonFragment([
                'key' => 'relay_url',
                'value' => 'https://relay.pbb.ph',
            ])
            ->assertJsonFragment([
                'key' => 'relay_token',
                'value' => '',
            ])
            ->assertJsonFragment([
                'key' => 'map_server_url',
                'value' => 'https://mapserver.pbb.ph',
            ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                'items' => [
                    ['key' => 'alert_level', 'value' => 'Elevated'],
                    ['key' => 'realtime_client_code', 'value' => 'clt_01KMXFPRXCTHJAG10DMACJFMYB'],
                    ['key' => 'realtime_project_code_server', 'value' => 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6'],
                    ['key' => 'realtime_project_code_caller', 'value' => 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9'],
                    ['key' => 'realtime_project_code_operator', 'value' => 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6'],
                    ['key' => 'realtime_project_code_media_ingest', 'value' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV'],
                    ['key' => 'realtime_url', 'value' => 'https://realtime-beta.pbb.ph'],
                    ['key' => 'realtime_backend_ingress_secret', 'value' => 'backend-secret-001'],
                    ['key' => 'realtime_token_signing_secret', 'value' => 'beta-secret-001'],
                    ['key' => 'relay_url', 'value' => 'https://relay-beta.pbb.ph'],
                    ['key' => 'relay_token', 'value' => 'relay-token-001'],
                    ['key' => 'map_server_url', 'value' => 'https://mapserver-beta.pbb.ph'],
                ],
            ])
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'alert_level',
                'value' => 'Elevated',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_client_code',
                'value' => 'clt_01KMXFPRXCTHJAG10DMACJFMYB',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_server',
                'value' => 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_caller',
                'value' => 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_operator',
                'value' => 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_media_ingest',
                'value' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_url',
                'value' => 'https://realtime-beta.pbb.ph',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_backend_ingress_secret',
                'value' => 'backend-secret-001',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_token_signing_secret',
                'value' => 'beta-secret-001',
            ])
            ->assertJsonFragment([
                'key' => 'relay_url',
                'value' => 'https://relay-beta.pbb.ph',
            ])
            ->assertJsonFragment([
                'key' => 'relay_token',
                'value' => 'relay-token-001',
            ])
            ->assertJsonFragment([
                'key' => 'map_server_url',
                'value' => 'https://mapserver-beta.pbb.ph',
            ]);
    }

    public function test_alert_level_update_queues_realtime_publish_when_backend_secret_is_configured(): void
    {
        Http::fake([
            'https://realtime.pbb.ph/api/v1/events/publish' => Http::response([
                'service' => 'PBB Realtime',
                'status' => 'accepted',
                'data' => [
                    'publish_id' => 'pub_01TEST',
                    'published' => true,
                ],
            ], 202),
        ]);

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                'items' => [
                    ['key' => 'alert_level', 'value' => 'Critical'],
                    ['key' => 'realtime_backend_ingress_secret', 'value' => 'backend-secret-001'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('meta.realtime_publish.status', 'accepted');

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
}
