<?php

namespace Tests\Feature\Internal;

use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeProductQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_product_query_publishes_authorized_incident_snapshot_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    'publish_id' => 'pub_01QUERY',
                    'published' => true,
                ],
            ], 202, [
                'X-Realtime-Trace-Id' => 'rt_query_trace',
            ]),
        ]);

        $this->setSetting('realtime_backend_ingress_secret', 'test-product-secret');
        $this->setSetting('realtime_client_code', 'clt_hotline');
        $this->setSetting('realtime_project_code_server', 'prj_hotline_server');
        $this->setSetting('realtime_url', 'https://realtime.test');

        [$citizen, $incidentId] = $this->seedIncidentSnapshotFixture();

        $response = $this->postJson('/api/internal/realtime/product-query', [
            'type' => 'product.query.request',
            'schema_version' => 1,
            'client_code' => 'clt_hotline',
            'project_code' => 'prj_citizen',
            'room' => 'presence.global.hotline',
            'request' => [
                'request_id' => 'qry_test_001',
                'query' => 'hotline.incident.snapshot',
                'context' => [
                    'incident_id' => $incidentId,
                ],
                'projection' => [
                    'preset' => 'status',
                ],
                'client_state' => [
                    'reason' => 'post-call-reconcile',
                ],
            ],
            'meta' => [
                'sender' => [
                    'user_id' => $citizen->id,
                    'display_name' => $citizen->name,
                    'project_code' => 'prj_citizen',
                    'app_code' => 'clt_hotline',
                ],
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'test-product-secret',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request_id', 'qry_test_001')
            ->assertJsonPath('query', 'hotline.incident.snapshot');

        Http::assertSent(function ($request) use ($incidentId): bool {
            $payload = $request->data();

            return $request->url() === 'https://realtime.test/api/v1/events/publish'
                && $request->hasHeader('X-Realtime-Backend-Secret', 'test-product-secret')
                && ($payload['client_code'] ?? null) === 'clt_hotline'
                && ($payload['project_code'] ?? null) === 'prj_hotline_server'
                && ($payload['room'] ?? null) === 'presence.global.hotline'
                && ($payload['event_type'] ?? null) === 'product.query.response'
                && ($payload['payload']['request_id'] ?? null) === 'qry_test_001'
                && ($payload['payload']['query'] ?? null) === 'hotline.incident.snapshot'
                && ($payload['payload']['status'] ?? null) === 'ok'
                && ($payload['payload']['data']['incident']['id'] ?? null) === $incidentId
                && ($payload['payload']['data']['incident']['status'] ?? null) === IncidentStatus::Deferred->value
                && ! array_key_exists('current_call_session', $payload['payload']['data']['incident'] ?? []);
        });
    }

    public function test_internal_product_query_rejects_invalid_secret(): void
    {
        $this->setSetting('realtime_backend_ingress_secret', 'expected-secret');

        $this->postJson('/api/internal/realtime/product-query', [
            'type' => 'product.query.request',
            'room' => 'presence.global.hotline',
            'request' => [
                'request_id' => 'qry_test_001',
                'query' => 'hotline.incident.snapshot',
                'context' => [
                    'incident_id' => 1,
                ],
            ],
            'meta' => [
                'sender' => [
                    'user_id' => 1,
                ],
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'wrong-secret',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid product query secret.');
    }

    public function test_internal_product_query_publishes_forbidden_response_for_wrong_sender(): void
    {
        Http::fake([
            '*' => Http::response(['data' => ['publish_id' => 'pub_01QUERY']], 202),
        ]);

        $this->setSetting('realtime_backend_ingress_secret', 'test-product-secret');
        $this->setSetting('realtime_client_code', 'clt_hotline');
        $this->setSetting('realtime_project_code_server', 'prj_hotline_server');
        $this->setSetting('realtime_url', 'https://realtime.test');

        [, $incidentId] = $this->seedIncidentSnapshotFixture();
        $intruder = User::factory()->create([
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);

        $this->postJson('/api/internal/realtime/product-query', [
            'type' => 'product.query.request',
            'room' => 'presence.global.hotline',
            'request' => [
                'request_id' => 'qry_test_002',
                'query' => 'hotline.incident.snapshot',
                'context' => [
                    'incident_id' => $incidentId,
                ],
            ],
            'meta' => [
                'sender' => [
                    'user_id' => $intruder->id,
                ],
            ],
        ], [
            'X-Realtime-Backend-Secret' => 'test-product-secret',
        ])->assertAccepted()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return ($payload['event_type'] ?? null) === 'product.query.response'
                && ($payload['payload']['request_id'] ?? null) === 'qry_test_002'
                && ($payload['payload']['status'] ?? null) === 'error'
                && ($payload['payload']['error']['code'] ?? null) === 'hotline.incident.snapshot.forbidden';
        });
    }

    /**
     * @return array{0:User,1:int}
     */
    private function seedIncidentSnapshotFixture(): array
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'status' => UserStatus::Active,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Deferred->value,
            'alert_level' => AlertLevel::Normal->value,
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('call_sessions')->insert([
            'incident_id' => $incidentId,
            'citizen_id' => $citizen->id,
            'status' => CallStatus::Ended->value,
            'outcome' => CallOutcome::EndedByCitizen->value,
            'started_at' => now()->subMinutes(2),
            'answered_at' => now()->subMinutes(2),
            'ended_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        return [$citizen, $incidentId];
    }

    private function setSetting(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => ['value' => $value]],
        );
    }
}
