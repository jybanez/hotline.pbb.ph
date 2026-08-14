<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use App\Support\Settings\SettingsService;
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
                'value' => 'clt_PBB_HOTLINE',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_server',
                'value' => 'prj_HOTLINE_SERVER',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_caller',
                'value' => 'prj_HOTLINE_CITIZEN',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_operator',
                'value' => 'prj_HOTLINE_OPERATOR',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_media_ingest',
                'value' => 'prj_HOTLINE_OPERATOR',
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
                'key' => 'relay_source_system',
                'value' => 'sitrep.app',
            ])
            ->assertJsonFragment([
                'key' => 'relay_target_systems',
                'value' => 'sitrep.ingestor',
            ])
            ->assertJsonFragment([
                'key' => 'map_server_url',
                'value' => 'https://mapserver.pbb.ph',
            ])
            ->assertJsonFragment([
                'key' => 'sitrep_periodic_generation_enabled',
                'value' => true,
            ])
            ->assertJsonPath('meta.sitrep_periodic.prepared_by_label', 'System Generated')
            ->assertJsonPath('meta.sitrep_periodic.coverage_source', 'relay_hub_json')
            ->assertJsonFragment([
                'key' => 'sitrep_periodic_normal_interval_minutes',
                'value' => 240,
            ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                'items' => [
                    ['key' => 'alert_level', 'value' => 'Elevated'],
                    ['key' => 'realtime_client_code', 'value' => 'clt_PBB_HOTLINE'],
                    ['key' => 'realtime_project_code_server', 'value' => 'prj_HOTLINE_SERVER'],
                    ['key' => 'realtime_project_code_caller', 'value' => 'prj_HOTLINE_CITIZEN'],
                    ['key' => 'realtime_project_code_operator', 'value' => 'prj_HOTLINE_OPERATOR'],
                    ['key' => 'realtime_project_code_media_ingest', 'value' => 'prj_HOTLINE_OPERATOR'],
                    ['key' => 'realtime_url', 'value' => 'https://realtime-beta.pbb.ph'],
                    ['key' => 'realtime_backend_ingress_secret', 'value' => 'backend-secret-001'],
                    ['key' => 'realtime_token_signing_secret', 'value' => 'beta-secret-001'],
                    ['key' => 'relay_url', 'value' => 'https://relay-beta.pbb.ph'],
                    ['key' => 'relay_token', 'value' => 'relay-token-001'],
                    ['key' => 'relay_source_system', 'value' => 'pbb.hotline'],
                    ['key' => 'relay_target_systems', 'value' => 'sitrep.ingestor,support.dispatch'],
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
                'value' => 'clt_PBB_HOTLINE',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_server',
                'value' => 'prj_HOTLINE_SERVER',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_caller',
                'value' => 'prj_HOTLINE_CITIZEN',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_operator',
                'value' => 'prj_HOTLINE_OPERATOR',
            ])
            ->assertJsonFragment([
                'key' => 'realtime_project_code_media_ingest',
                'value' => 'prj_HOTLINE_OPERATOR',
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
                'key' => 'relay_source_system',
                'value' => 'pbb.hotline',
            ])
            ->assertJsonFragment([
                'key' => 'relay_target_systems',
                'value' => 'sitrep.ingestor,support.dispatch',
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
                && $request['client_code'] === 'clt_PBB_HOTLINE'
                && $request['project_code'] === 'prj_HOTLINE_SERVER'
                && $request['room'] === 'hotline.settings.global'
                && $request['event_type'] === 'hotline.alert_level.changed'
                && $request['payload']['alert_level'] === 'Critical';
        });
    }

    public function test_account_secrets_are_available_in_runtime_settings_payload_for_admin_password_fields(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);
        $settings = app(SettingsService::class);
        $settings->set('account_sso_client_secret', 'oauth-secret-001');
        $settings->set('account_admin_api_token', 'admin-token-001');

        $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'account_sso_client_secret',
                'value' => 'oauth-secret-001',
            ])
            ->assertJsonFragment([
                'key' => 'account_admin_api_token',
                'value' => 'admin-token-001',
            ])
            ->assertJsonMissing([
                'key' => 'account_sso_client_secret_configured',
            ])
            ->assertJsonMissing([
                'key' => 'account_admin_api_token_configured',
            ]);
    }

    public function test_account_secret_values_are_persisted_normally_when_submitted(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);
        $settings = app(SettingsService::class);
        $settings->set('account_sso_client_secret', 'oauth-secret-001');
        $settings->set('account_admin_api_token', 'admin-token-001');

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                'items' => [
                    ['key' => 'account_sso_enabled', 'value' => true],
                    ['key' => 'account_sso_client_secret', 'value' => 'oauth-secret-002'],
                    ['key' => 'account_admin_api_enabled', 'value' => true],
                    ['key' => 'account_admin_api_token', 'value' => 'admin-token-002'],
                ],
            ])
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'account_sso_client_secret',
                'value' => 'oauth-secret-002',
            ])
            ->assertJsonFragment([
                'key' => 'account_admin_api_token',
                'value' => 'admin-token-002',
            ]);

        $this->assertSame('oauth-secret-002', $settings->get('account_sso_client_secret'));
        $this->assertSame('admin-token-002', $settings->get('account_admin_api_token'));
    }

    public function test_account_trusted_client_fields_are_not_in_admin_settings_payload(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);
        $settings = app(SettingsService::class);
        $settings->set('account_sso_base_url', 'https://account.local.test');
        $settings->set('account_sso_client_id', 'hotline-client-001');
        $settings->set('account_sso_redirect_uri', 'https://hotline.local.test/auth/account/callback');
        $settings->set('account_sso_post_logout_redirect_uri', 'https://hotline.local.test');
        $settings->set('account_sso_scopes', 'openid profile email');
        $settings->set('account_sso_ca_bundle', 'C:\\certs\\account-ca.pem');

        $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonMissing(['key' => 'account_sso_base_url'])
            ->assertJsonMissing(['key' => 'account_sso_client_id'])
            ->assertJsonMissing(['key' => 'account_sso_redirect_uri'])
            ->assertJsonMissing(['key' => 'account_sso_post_logout_redirect_uri'])
            ->assertJsonMissing(['key' => 'account_sso_scopes'])
            ->assertJsonMissing(['key' => 'account_sso_ca_bundle'])
            ->assertJsonMissing(['value' => 'https://account.local.test'])
            ->assertJsonMissing(['value' => 'hotline-client-001'])
            ->assertJsonMissing(['value' => 'https://hotline.local.test/auth/account/callback'])
            ->assertJsonMissing(['value' => 'https://hotline.local.test'])
            ->assertJsonMissing(['value' => 'openid profile email'])
            ->assertJsonMissing(['value' => 'C:\\certs\\account-ca.pem']);
    }

    public function test_saving_visible_account_settings_preserves_hidden_trusted_client_values(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);
        $settings = app(SettingsService::class);
        $hiddenValues = [
            'account_sso_base_url' => 'https://account.local.test',
            'account_sso_client_id' => 'hotline-client-001',
            'account_sso_redirect_uri' => 'https://hotline.local.test/auth/account/callback',
            'account_sso_post_logout_redirect_uri' => 'https://hotline.local.test',
            'account_sso_scopes' => 'openid profile email',
            'account_sso_ca_bundle' => 'C:\\certs\\account-ca.pem',
        ];

        foreach ($hiddenValues as $key => $value) {
            $settings->set($key, $value);
        }

        $this->actingAs($admin)
            ->postJson('/api/admin/settings', [
                'items' => [
                    ['key' => 'account_sso_enabled', 'value' => true],
                    ['key' => 'account_sso_client_secret', 'value' => 'oauth-secret-002'],
                    ['key' => 'account_admin_api_enabled', 'value' => true],
                    ['key' => 'account_admin_api_client', 'value' => 'pbb-account'],
                    ['key' => 'account_admin_api_token', 'value' => 'admin-token-002'],
                ],
            ])
            ->assertOk()
            ->assertJsonMissing(['key' => 'account_sso_base_url'])
            ->assertJsonMissing(['key' => 'account_sso_client_id'])
            ->assertJsonMissing(['key' => 'account_sso_redirect_uri'])
            ->assertJsonMissing(['key' => 'account_sso_post_logout_redirect_uri'])
            ->assertJsonMissing(['key' => 'account_sso_scopes'])
            ->assertJsonMissing(['key' => 'account_sso_ca_bundle']);

        foreach ($hiddenValues as $key => $value) {
            $this->assertSame($value, $settings->get($key), "{$key} changed unexpectedly.");
        }
    }

    public function test_account_runtime_settings_ui_hides_trusted_client_fields_and_status_fields(): void
    {
        $adminSurface = file_get_contents(resource_path('js/surfaces/adminSurface.js'));

        $this->assertIsString($adminSurface);
        $this->assertStringNotContainsString("id: 'account_sso_base_url'", $adminSurface);
        $this->assertStringNotContainsString("id: 'account_sso_client_id'", $adminSurface);
        $this->assertStringNotContainsString("id: 'account_sso_redirect_uri'", $adminSurface);
        $this->assertStringNotContainsString("id: 'account_sso_post_logout_redirect_uri'", $adminSurface);
        $this->assertStringNotContainsString("id: 'account_sso_scopes'", $adminSurface);
        $this->assertStringNotContainsString("id: 'account_sso_ca_bundle'", $adminSurface);
        $this->assertStringNotContainsString("id: 'account_sso_client_secret_configured'", $adminSurface);
        $this->assertStringNotContainsString("id: 'account_admin_api_token_configured'", $adminSurface);
        $this->assertStringContainsString("id: 'account_sso_client_secret'", $adminSurface);
        $this->assertStringContainsString("id: 'account_admin_api_token'", $adminSurface);
        $this->assertStringContainsString("id: 'account_admin_api_client'", $adminSurface);
    }
}
