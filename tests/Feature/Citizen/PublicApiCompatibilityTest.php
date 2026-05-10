<?php

namespace Tests\Feature\Citizen;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicApiCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_citizen_and_legacy_caller_shared_read_endpoints_return_equivalent_payloads(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $activeIncidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $citizen->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'location' => 'Guadalupe, Cebu City',
            'called_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('incidents')->insert([
            'caller_id' => $citizen->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Resolved->value,
            'alert_level' => 'Normal',
            'location' => 'Lahug, Cebu City',
            'called_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDay(),
        ]);

        foreach ([
            '/home',
            '/incidents/history',
        ] as $path) {
            $citizenResponse = $this->actingAs($citizen)
                ->getJson("/api/citizen{$path}")
                ->assertOk();

            $legacyCallerResponse = $this->actingAs($citizen)
                ->getJson("/api/caller{$path}")
                ->assertOk();

            self::assertSame(
                $citizenResponse->json(),
                $legacyCallerResponse->json(),
                "Expected /api/citizen{$path} and /api/caller{$path} to match.",
            );
        }
    }

    public function test_citizen_incident_payload_omits_legacy_caller_aliases(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'called_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);

        $callSessionId = DB::table('call_sessions')->insertGetId([
            'incident_id' => $incidentId,
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'status' => 'ended',
            'outcome' => 'ended_by_operator',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('call_participants')->insert([
            [
                'call_session_id' => $callSessionId,
                'user_id' => $citizen->id,
                'participant_role' => 'citizen',
                'joined_at' => now(),
                'created_at' => now(),
            ],
            [
                'call_session_id' => $callSessionId,
                'user_id' => $operator->id,
                'participant_role' => 'operator',
                'joined_at' => now(),
                'created_at' => now(),
            ],
        ]);

        $payload = $this->actingAs($citizen)
            ->getJson("/api/citizen/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('citizen_id', $citizen->id)
            ->assertJsonPath('citizen.name', $citizen->name)
            ->assertJsonPath('actual_citizen_name', 'Maria Santos')
            ->assertJsonPath('actual_citizen_relationship', 'Self')
            ->assertJsonPath('citizen_location.latitude', 10.3157)
            ->assertJsonPath('current_call_session.citizen_id', $citizen->id)
            ->json();

        foreach ([
            'caller_id',
            'caller',
            'actual_caller_name',
            'actual_caller_relationship',
            'caller_location',
        ] as $legacyKey) {
            self::assertArrayNotHasKey($legacyKey, $payload);
        }

        self::assertArrayNotHasKey('caller_id', $payload['current_call_session']);
        self::assertArrayNotHasKey('caller_id', $payload['call_history'][0]);
    }

    public function test_legacy_caller_incident_endpoint_keeps_compatibility_aliases(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'called_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($citizen)
            ->getJson("/api/caller/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('citizen_id', $citizen->id)
            ->assertJsonPath('caller_id', $citizen->id)
            ->assertJsonPath('citizen.name', $citizen->name)
            ->assertJsonPath('caller.name', $citizen->name)
            ->assertJsonPath('actual_citizen_name', 'Maria Santos')
            ->assertJsonPath('actual_caller_name', 'Maria Santos')
            ->assertJsonPath('citizen_location.latitude', 10.3157)
            ->assertJsonPath('caller_location.latitude', 10.3157);
    }

    public function test_citizen_and_legacy_caller_call_attempt_conflicts_return_equivalent_payloads(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $citizenResponse = $this->actingAs($citizen)
            ->postJson('/api/citizen/call-attempts')
            ->assertStatus(409);

        $legacyCallerResponse = $this->actingAs($citizen)
            ->postJson('/api/caller/call-attempts')
            ->assertStatus(409);

        self::assertSame($citizenResponse->json(), $legacyCallerResponse->json());
    }
}
