<?php

namespace Tests\Feature\Citizen;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconnectFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_caller_can_start_and_cancel_unanswered_reconnect(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($caller)
            ->postJson("/api/caller/incidents/{$incidentId}/reconnect");

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('call_session.incident_id', $incidentId)
            ->assertJsonPath('call_session.status', 'calling');

        $callSessionId = $response->json('call_session.id');

        $this->assertDatabaseHas('call_participants', [
            'call_session_id' => $callSessionId,
            'user_id' => $caller->id,
            'participant_role' => 'caller',
        ]);

        $this->actingAs($caller)
            ->postJson("/api/caller/call-sessions/{$callSessionId}/cancel")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('call_session.status', 'ended')
            ->assertJsonPath('call_session.outcome', 'cancelled_by_caller');
    }

    public function test_reconnect_is_blocked_when_assigned_operator_is_busy_on_another_active_incident(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $otherCaller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Deferred->value,
            'alert_level' => 'Normal',
            'called_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        DB::table('incidents')->insert([
            'caller_id' => $otherCaller->id,
            'actual_caller_name' => $otherCaller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($caller)
            ->postJson("/api/caller/incidents/{$incidentId}/reconnect")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Reconnect is blocked because the assigned operator is currently busy.');

        $this->assertDatabaseCount('call_sessions', 0);
    }

    public function test_assigned_operator_can_answer_reconnect_session(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callSessionId = DB::table('call_sessions')->insertGetId([
            'incident_id' => $incidentId,
            'caller_id' => $caller->id,
            'status' => 'calling',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('call_participants')->insert([
            'call_session_id' => $callSessionId,
            'user_id' => $caller->id,
            'participant_role' => 'caller',
            'joined_at' => now(),
            'created_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/call-sessions/{$callSessionId}/answer")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('call_session.status', 'in_progress')
            ->assertJsonPath('call_session.outcome', 'answered');

        $this->assertDatabaseHas('call_participants', [
            'call_session_id' => $callSessionId,
            'user_id' => $operator->id,
            'participant_role' => 'operator',
        ]);
    }
}
