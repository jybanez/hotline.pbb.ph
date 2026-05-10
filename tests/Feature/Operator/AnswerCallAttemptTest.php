<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Calls\Models\CallSession;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnswerCallAttemptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_answering_routed_call_creates_incident_and_first_call_session(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $start = $this->actingAs($caller)->postJson('/api/citizen/call-attempts');

        $attemptId = $start->json('attempt.id');
        $operatorAttemptId = $start->json('operator_attempt.id');

        $this->assertDatabaseCount('incidents', 0);

        $this->actingAs($operator)
            ->postJson("/api/operator/call-attempt-operator-attempts/{$operatorAttemptId}/answer")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt.id', $attemptId)
            ->assertJsonPath('attempt.outcome', 'answered')
            ->assertJsonPath('incident.status', 'Active')
            ->assertJsonPath('call_session.status', 'in_progress')
            ->assertJsonPath('call_session.answered_at', null);

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseCount('call_sessions', 1);
        $this->assertDatabaseCount('call_participants', 2);
        $this->assertDatabaseHas('incidents', [
            'caller_id' => $caller->id,
            'citizen_id' => $caller->id,
        ]);
        $this->assertDatabaseHas('call_sessions', [
            'caller_id' => $caller->id,
            'citizen_id' => $caller->id,
        ]);
        $this->assertDatabaseHas('call_participants', [
            'user_id' => $caller->id,
            'participant_role' => 'citizen',
        ]);
    }

    public function test_operator_can_mark_active_call_session_ready(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $start = $this->actingAs($caller)->postJson('/api/citizen/call-attempts');
        $operatorAttemptId = $start->json('operator_attempt.id');

        $answer = $this->actingAs($operator)
            ->postJson("/api/operator/call-attempt-operator-attempts/{$operatorAttemptId}/answer")
            ->assertOk();

        $callSessionId = (int) $answer->json('call_session.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/call-sessions/{$callSessionId}/ready")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('call_session.id', $callSessionId);

        $this->assertDatabaseHas('call_sessions', [
            'id' => $callSessionId,
            'status' => 'in_progress',
        ]);

        $this->assertNotNull(
            \App\Domain\Calls\Models\CallSession::query()->findOrFail($callSessionId)->answered_at
        );
    }

    public function test_operator_can_use_citizen_cancel_alias_for_routed_attempt(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $start = $this->actingAs($citizen)->postJson('/api/citizen/call-attempts');
        $attemptId = (int) $start->json('attempt.id');
        $operatorAttemptId = (int) $start->json('operator_attempt.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/call-attempt-operator-attempts/{$operatorAttemptId}/citizen-cancel")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt.id', $attemptId)
            ->assertJsonPath('attempt.outcome', 'cancelled_by_citizen');

        $this->assertDatabaseHas('call_attempts', [
            'id' => $attemptId,
            'outcome' => 'cancelled_by_citizen',
        ]);
    }

    public function test_operator_can_mark_unanswered_routed_attempt_as_timed_out(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $start = $this->actingAs($citizen)->postJson('/api/citizen/call-attempts');
        $attemptId = (int) $start->json('attempt.id');
        $operatorAttemptId = (int) $start->json('operator_attempt.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/call-attempt-operator-attempts/{$operatorAttemptId}/timeout")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt.id', $attemptId)
            ->assertJsonPath('attempt.outcome', 'timed_out');

        $this->assertDatabaseHas('call_attempts', [
            'id' => $attemptId,
            'outcome' => 'timed_out',
        ]);

        $this->assertDatabaseHas('call_attempt_operator_attempts', [
            'id' => $operatorAttemptId,
            'outcome' => 'timed_out',
        ]);
    }

    public function test_operator_ready_can_use_precise_gate_lift_timestamp(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $start = $this->actingAs($caller)->postJson('/api/citizen/call-attempts');
        $operatorAttemptId = $start->json('operator_attempt.id');

        $answer = $this->actingAs($operator)
            ->postJson("/api/operator/call-attempt-operator-attempts/{$operatorAttemptId}/answer")
            ->assertOk();

        $callSessionId = (int) $answer->json('call_session.id');
        $startedAt = CallSession::query()->findOrFail($callSessionId)->started_at;
        $gateLiftedAt = $startedAt
            ->copy()
            ->addMilliseconds(736)
            ->utc()
            ->format('Y-m-d\\TH:i:s.v\\Z');

        $response = $this->actingAs($operator)
            ->postJson("/api/operator/call-sessions/{$callSessionId}/ready", [
                'answered_at' => $gateLiftedAt,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('call_session.id', $callSessionId);

        $answeredAt = \App\Domain\Calls\Models\CallSession::query()->findOrFail($callSessionId)->answered_at;

        $expected = Carbon::parse($gateLiftedAt)->utc();
        $actualModel = $answeredAt?->utc();
        $actualResponse = Carbon::parse($response->json('call_session.answered_at'))->utc();

        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($expected->format('Y-m-d\\TH:i:s'), $actualModel?->format('Y-m-d\\TH:i:s'));
            $this->assertSame($expected->format('Y-m-d\\TH:i:s'), $actualResponse->format('Y-m-d\\TH:i:s'));
            return;
        }

        $this->assertSame($expected->format('Y-m-d\\TH:i:s.u\\Z'), $actualModel?->format('Y-m-d\\TH:i:s.u\\Z'));
        $this->assertSame($expected->format('Y-m-d\\TH:i:s.u\\Z'), $actualResponse->format('Y-m-d\\TH:i:s.u\\Z'));
    }
}
