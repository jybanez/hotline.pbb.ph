<?php

namespace Tests\Feature\Realtime;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallbackCallAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_is_authorized_deduplicated_verified_and_cancelled(): void
    {
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\VerifyCsrfToken::class);
        $operator = User::factory()->create(['role' => UserRole::Operator, 'status' => UserStatus::Active]);
        $citizen = User::factory()->create(['role' => UserRole::Citizen, 'status' => UserStatus::Active]);
        $other = User::factory()->create(['role' => UserRole::Operator, 'status' => UserStatus::Active]);
        $incident = Incident::create(['called_at' => now(), 'alert_level' => \App\Domain\Shared\Enums\AlertLevel::Normal, 'operator_id' => $operator->id, 'citizen_id' => $citizen->id, 'status' => IncidentStatus::Deferred]);
        $this->actingAs($other)->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertForbidden();
        $response = $this->actingAs($operator)->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertCreated();
        $id = $response->json('attempt.id');
        $this->assertTrue($response->json('attempt.callback'));
        $this->assertNull($response->json('attempt.answered_at'));
        $this->actingAs($operator)->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertConflict();
        $this->actingAs($citizen)->getJson('/api/citizen/callback-call-attempts/'.$id)->assertOk();
        $this->actingAs($operator)->postJson('/api/operator/callback-call-attempts/'.$id.'/cancel')->assertOk();
        $this->assertSame(CallStatus::Ended, CallAttempt::findOrFail($id)->status);
        $this->actingAs($citizen)->getJson('/api/citizen/callback-call-attempts/'.$id)->assertConflict();
    }

    public function test_callback_answer_creates_one_session_and_preserves_citizen_answer_semantics(): void
    {
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\VerifyCsrfToken::class);
        $operator = User::factory()->create(['role' => UserRole::Operator, 'status' => UserStatus::Active]);
        $citizen = User::factory()->create(['role' => UserRole::Citizen, 'status' => UserStatus::Active]);
        $incident = Incident::create(['called_at' => now(), 'alert_level' => \App\Domain\Shared\Enums\AlertLevel::Normal,
            'operator_id' => $operator->id, 'citizen_id' => $citizen->id, 'status' => IncidentStatus::Deferred]);
        $id = $this->actingAs($operator)->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertCreated()->json('attempt.id');
        $this->actingAs($citizen)->postJson('/api/operator/callback-call-attempts/'.$id.'/answer')->assertRedirect('/unauthorized');
        $response = $this->actingAs($operator)->postJson('/api/operator/callback-call-attempts/'.$id.'/answer')->assertOk();
        $this->assertNotNull($response->json('attempt.answered_at'));
        $this->assertNull($response->json('attempt.answered_by_operator_id'));
        $this->assertNull($response->json('call_session.answered_at'));
        $this->assertCount(2, $response->json('call_session.participants'));
        $this->actingAs($operator)->postJson('/api/operator/callback-call-attempts/'.$id.'/answer')->assertConflict();
        $this->assertDatabaseCount('call_sessions', 1);
    }

    public function test_changed_assignment_status_and_recipient_block_answer_without_changing_incident(): void
    {
        foreach (['assignment', 'status', 'recipient', 'suspended'] as $change) {
            [$operator, $citizen, $incident] = $this->callbackFixture();
            $id = $this->actingAs($operator)->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertCreated()->json('attempt.id');
            if ($change === 'assignment') {
                $incident->update(['operator_id' => User::factory()->create(['role' => UserRole::Operator])->id]);
            }
            if ($change === 'status') {
                $incident->update(['status' => IncidentStatus::Resolved]);
            }
            if ($change === 'recipient') {
                $incident->update(['citizen_id' => User::factory()->create(['role' => UserRole::Citizen])->id]);
            }
            if ($change === 'suspended') {
                $citizen->update(['status' => UserStatus::Suspended]);
            }
            $before = $incident->fresh()->getAttributes();
            $response = $this->actingAs($operator)->postJson('/api/operator/callback-call-attempts/'.$id.'/answer');
            $change === 'assignment' ? $response->assertForbidden() : $response->assertConflict();
            $this->assertSame($before, $incident->fresh()->getAttributes());
            $this->assertSame(CallStatus::Calling, CallAttempt::findOrFail($id)->status);
            // Clean up this fixture so it cannot interfere with the next case.
            CallAttempt::findOrFail($id)->update(['status' => CallStatus::Ended]);
        }
        $this->assertDatabaseCount('call_sessions', 0);
    }

    public function test_decline_is_recorded_and_cancellation_cannot_overwrite_answer(): void
    {
        [$operator, , $incident] = $this->callbackFixture();
        $id = $this->actingAs($operator)->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertCreated()->json('attempt.id');
        $this->postJson('/api/operator/callback-call-attempts/'.$id.'/cancel', ['outcome' => 'answered'])->assertUnprocessable();
        $this->postJson('/api/operator/callback-call-attempts/'.$id.'/cancel', ['outcome' => 'declined_by_citizen'])->assertOk();
        $this->assertSame('declined_by_citizen', CallAttempt::findOrFail($id)->outcome->value);
        $this->postJson('/api/operator/callback-call-attempts/'.$id.'/answer')->assertConflict();
        $id = $this->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertCreated()->json('attempt.id');
        $this->postJson('/api/operator/callback-call-attempts/'.$id.'/answer')->assertOk();
        $this->postJson('/api/operator/callback-call-attempts/'.$id.'/cancel')->assertOk();
        $this->assertSame('answered', CallAttempt::findOrFail($id)->outcome->value);
        $this->assertDatabaseCount('call_sessions', 1);
    }

    public function test_pending_call_on_another_incident_blocks_callback_and_reconnect_conflicts(): void
    {
        [$operator, $citizen, $incident] = $this->callbackFixture();
        $otherIncident = Incident::create(['called_at' => now(), 'alert_level' => \App\Domain\Shared\Enums\AlertLevel::Normal,
            'operator_id' => $operator->id, 'citizen_id' => $citizen->id, 'status' => IncidentStatus::Deferred]);
        $id = $this->actingAs($operator)->postJson('/api/operator/incidents/'.$incident->id.'/callback-call')->assertCreated()->json('attempt.id');
        $this->postJson('/api/operator/incidents/'.$otherIncident->id.'/callback-call')->assertConflict();
        $this->actingAs($citizen)->postJson('/api/citizen/incidents/'.$incident->id.'/reconnect')->assertConflict();
        $this->actingAs($operator)->postJson('/api/operator/callback-call-attempts/'.$id.'/cancel')->assertOk();
        $this->assertDatabaseCount('call_attempts', 1);
    }

    private function callbackFixture(): array
    {
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\VerifyCsrfToken::class);
        $operator = User::factory()->create(['role' => UserRole::Operator, 'status' => UserStatus::Active]);
        $citizen = User::factory()->create(['role' => UserRole::Citizen, 'status' => UserStatus::Active]);
        $incident = Incident::create(['called_at' => now(), 'alert_level' => \App\Domain\Shared\Enums\AlertLevel::Normal,
            'operator_id' => $operator->id, 'citizen_id' => $citizen->id, 'status' => IncidentStatus::Deferred]);

        return [$operator, $citizen, $incident];
    }
}
