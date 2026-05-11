<?php

namespace Tests\Feature\Realtime;

use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_citizen_incident_chat_admission_returns_signed_realtime_payload(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => AlertLevel::Normal->value,
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storeRealtimeSettings();

        $this->actingAs($citizen)
            ->postJson('/api/realtime/admission/citizen', [
                'context_type' => 'incident_chat',
                'context_id' => $incidentId,
            ])
            ->assertOk()
            ->assertJsonPath('app_code', 'clt_01KMXFPRXCTHJAG10DMACJFMYB')
            ->assertJsonPath('project_code', 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9')
            ->assertJsonPath('room', 'chat.thread.incident.'.$incidentId)
            ->assertJsonMissingPath('call_room')
            ->assertJsonPath('session.allowed_rooms.0', 'chat.thread.incident.'.$incidentId)
            ->assertJsonPath('websocket_url', 'wss://realtime-beta.pbb.ph/realtime');
    }

    public function test_citizen_settings_stream_admission_returns_shared_settings_room(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->storeRealtimeSettings();

        $this->actingAs($citizen)
            ->postJson('/api/realtime/admission/citizen', [
                'context_type' => 'settings_stream',
                'context_id' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('app_code', 'clt_01KMXFPRXCTHJAG10DMACJFMYB')
            ->assertJsonPath('project_code', 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9')
            ->assertJsonPath('room', 'hotline.settings.global')
            ->assertJsonPath('session.allowed_rooms.0', 'hotline.settings.global')
            ->assertJsonPath('session.allowed_room_prefixes.0', 'hotline.settings.')
            ->assertJsonPath('websocket_url', 'wss://realtime-beta.pbb.ph/realtime');
    }

    public function test_citizen_call_session_admission_returns_incident_chat_and_call_session_rooms(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => AlertLevel::Normal->value,
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callSessionId = DB::table('call_sessions')->insertGetId([
            'incident_id' => $incidentId,
            'citizen_id' => $citizen->id,
            'status' => CallStatus::InProgress->value,
            'outcome' => CallOutcome::Answered->value,
            'started_at' => now(),
            'answered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storeRealtimeSettings();

        $this->actingAs($citizen)
            ->postJson('/api/realtime/admission/citizen', [
                'context_type' => 'call_session',
                'context_id' => $callSessionId,
            ])
            ->assertOk()
            ->assertJsonPath('project_code', 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9')
            ->assertJsonPath('room', 'chat.thread.incident.'.$incidentId)
            ->assertJsonPath('call_room', 'call.session.'.$callSessionId)
            ->assertJsonPath('session.allowed_rooms.0', 'chat.thread.incident.'.$incidentId)
            ->assertJsonPath('session.allowed_rooms.1', 'call.session.'.$callSessionId);
    }

    public function test_operator_dashboard_presence_admission_returns_presence_room(): void
    {
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $this->storeRealtimeSettings([
            'realtime_url' => 'wss://realtime-beta.pbb.ph/realtime',
        ]);

        $this->actingAs($operator)
            ->postJson('/api/realtime/admission/operator', [
                'context_type' => 'dashboard_presence',
                'context_id' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('app_code', 'clt_01KMXFPRXCTHJAG10DMACJFMYB')
            ->assertJsonPath('project_code', 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6')
            ->assertJsonPath('room', 'presence.workspace.operator')
            ->assertJsonPath('session.allowed_rooms.0', 'presence.workspace.operator')
            ->assertJsonPath('websocket_url', 'wss://realtime-beta.pbb.ph/realtime');
    }

    public function test_realtime_admission_requires_signing_secret_configuration(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => AlertLevel::Normal->value,
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($citizen)
            ->postJson('/api/realtime/admission/citizen', [
                'context_type' => 'incident_chat',
                'context_id' => $incidentId,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Realtime token signing secret is not configured.');
    }

    public function test_legacy_caller_admission_endpoint_is_removed(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->storeRealtimeSettings();

        $this->actingAs($citizen)
            ->postJson('/api/realtime/admission/caller', [
                'context_type' => 'settings_stream',
                'context_id' => 0,
            ])
            ->assertNotFound();
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function storeRealtimeSettings(array $overrides = []): void
    {
        $items = array_merge([
            'realtime_client_code' => 'clt_01KMXFPRXCTHJAG10DMACJFMYB',
            'realtime_project_code_server' => 'prj_01KNGH5A0VAVWDT5Y8B35F2CV6',
            'realtime_project_code_caller' => 'prj_01KMXG0AXB2S9CXS0YK4AFT2C9',
            'realtime_project_code_operator' => 'prj_01KMXG0AXH58JZ2NQSGE5AYMH6',
            'realtime_project_code_media_ingest' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
            'realtime_url' => 'https://realtime-beta.pbb.ph',
            'realtime_backend_ingress_secret' => 'backend-secret-001',
            'realtime_token_signing_secret' => 'beta-secret-001',
        ], $overrides);

        foreach ($items as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => ['value' => $value]],
            );
        }
    }
}
