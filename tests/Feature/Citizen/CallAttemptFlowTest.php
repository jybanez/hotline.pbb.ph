<?php

namespace Tests\Feature\Citizen;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CallAttemptFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_citizen_can_start_and_cancel_a_new_call_attempt_when_operator_is_available(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $response = $this->actingAs($citizen)->postJson('/api/citizen/call-attempts', [
            'caller_latitude' => 10.3306796,
            'caller_longitude' => 123.8279630,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt.incident_id', null)
            ->assertJsonPath('attempt.status', 'calling')
            ->assertJsonPath('operator_attempt.status', 'calling');

        $attemptId = $response->json('attempt.id');

        $this->assertDatabaseCount('incidents', 0);

        $this->actingAs($citizen)
            ->postJson("/api/citizen/call-attempts/{$attemptId}/cancel")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'ended')
            ->assertJsonPath('attempt.outcome', 'cancelled_by_caller');
    }

    public function test_citizen_can_mark_unanswered_call_attempt_as_timed_out(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $start = $this->actingAs($citizen)->postJson('/api/citizen/call-attempts');
        $attemptId = $start->json('attempt.id');
        $operatorAttemptId = $start->json('operator_attempt.id');

        $this->actingAs($citizen)
            ->postJson("/api/citizen/call-attempts/{$attemptId}/timeout")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'ended')
            ->assertJsonPath('attempt.outcome', 'timed_out');

        $this->assertDatabaseHas('call_attempt_operator_attempts', [
            'id' => $operatorAttemptId,
            'status' => 'ended',
            'outcome' => 'timed_out',
        ]);
    }

    public function test_citizen_cannot_start_new_call_attempt_when_no_operator_is_available(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($citizen)
            ->postJson('/api/citizen/call-attempts')
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
    }

    public function test_legacy_caller_api_routes_are_logged(): void
    {
        Log::spy();

        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($citizen)
            ->getJson('/api/caller/home')
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Hotline legacy caller route used.', \Mockery::on(
                fn (array $context): bool => ($context['contract'] ?? null) === 'public-api'
                    && ($context['method'] ?? null) === 'GET'
                    && ($context['path'] ?? null) === 'api/caller/home'
                    && (int) ($context['user_id'] ?? 0) === (int) $citizen->id
                    && ($context['user_role'] ?? null) === UserRole::Citizen->value
            ));
    }
}
