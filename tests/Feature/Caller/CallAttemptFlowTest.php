<?php

namespace Tests\Feature\Caller;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallAttemptFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_caller_can_start_and_cancel_a_new_call_attempt_when_operator_is_available(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $response = $this->actingAs($caller)->postJson('/api/caller/call-attempts', [
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

        $this->actingAs($caller)
            ->postJson("/api/caller/call-attempts/{$attemptId}/cancel")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'ended')
            ->assertJsonPath('attempt.outcome', 'cancelled_by_caller');
    }

    public function test_caller_can_mark_unanswered_call_attempt_as_timed_out(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $start = $this->actingAs($caller)->postJson('/api/caller/call-attempts');
        $attemptId = $start->json('attempt.id');
        $operatorAttemptId = $start->json('operator_attempt.id');

        $this->actingAs($caller)
            ->postJson("/api/caller/call-attempts/{$attemptId}/timeout")
            ->assertOk()
            ->assertJsonPath('attempt.status', 'ended')
            ->assertJsonPath('attempt.outcome', 'timed_out');

        $this->assertDatabaseHas('call_attempt_operator_attempts', [
            'id' => $operatorAttemptId,
            'status' => 'ended',
            'outcome' => 'timed_out',
        ]);
    }

    public function test_caller_cannot_start_new_call_attempt_when_no_operator_is_available(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($caller)
            ->postJson('/api/caller/call-attempts')
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
    }
}
