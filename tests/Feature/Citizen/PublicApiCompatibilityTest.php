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

    public function test_citizen_and_legacy_caller_read_endpoints_return_equivalent_payloads(): void
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
            '/incidents/current',
            '/incidents/history',
            "/incidents/{$activeIncidentId}",
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
