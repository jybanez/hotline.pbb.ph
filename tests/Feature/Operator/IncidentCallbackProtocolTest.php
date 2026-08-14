<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentCallbackProtocolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_assigned_operator_can_create_callback_for_active_or_deferred_incident(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $activeIncidentId = $this->incident($operator, $citizen, IncidentStatus::Active);
        $deferredIncidentId = $this->incident($operator, $citizen, IncidentStatus::Deferred);

        $this->actingAs($operator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $activeIncidentId,
                'reason' => 'operator_followup',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('callback.incident_id', $activeIncidentId)
            ->assertJsonPath('callback.status', 'pending');

        $this->actingAs($operator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $deferredIncidentId,
                'reason' => 'reconnect_required',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('callback.incident_id', $deferredIncidentId);
    }

    public function test_non_assigned_operator_cannot_create_call_update_or_complete_callback(): void
    {
        [$owner, $citizen] = $this->operatorAndCitizen();
        $otherOperator = User::factory()->create(['role' => UserRole::Operator]);
        $incidentId = $this->incident($owner, $citizen, IncidentStatus::Active);

        $createResponse = $this->actingAs($owner)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $incidentId,
                'reason' => 'operator_followup',
            ])
            ->assertCreated();

        $callbackId = $createResponse->json('callback.id');

        $this->actingAs($otherOperator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $incidentId,
                'reason' => 'reconnect_required',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Operator is not assigned to this incident.');

        $this->actingAs($otherOperator)
            ->postJson("/api/operator/callbacks/{$callbackId}/call")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Operator is not assigned to this incident.');

        $this->actingAs($otherOperator)
            ->postJson("/api/operator/callbacks/{$callbackId}/attempts", [
                'result' => 'no_answer',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Operator is not assigned to this incident.');

        $this->actingAs($otherOperator)
            ->postJson("/api/operator/callbacks/{$callbackId}/complete", [
                'final_disposition' => 'Caller reached by another operator.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Operator is not assigned to this incident.');
    }

    public function test_callback_cannot_be_created_for_resolved_or_discarded_incident(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();

        foreach ([IncidentStatus::Resolved, IncidentStatus::Discarded] as $status) {
            $incidentId = $this->incident($operator, $citizen, $status);

            $this->actingAs($operator)
                ->postJson('/api/operator/callbacks', [
                    'incident_id' => $incidentId,
                    'reason' => 'operator_followup',
                ])
                ->assertStatus(409)
                ->assertJsonPath('message', 'Callback is only available for active or deferred incidents.');
        }
    }

    public function test_duplicate_open_callback_is_idempotently_returned(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $incidentId = $this->incident($operator, $citizen, IncidentStatus::Active);

        $first = $this->actingAs($operator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $incidentId,
                'reason' => 'operator_followup',
            ])
            ->assertCreated();

        $second = $this->actingAs($operator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $incidentId,
                'reason' => 'operator_followup',
            ])
            ->assertOk();

        $this->assertSame($first->json('callback.id'), $second->json('callback.id'));
        $this->assertDatabaseCount('callback_cases', 1);
    }

    public function test_source_call_session_must_belong_to_callback_incident_and_citizen(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $otherCitizen = User::factory()->create(['role' => UserRole::Citizen]);
        $incidentId = $this->incident($operator, $citizen, IncidentStatus::Active);
        $otherIncidentId = $this->incident($operator, $otherCitizen, IncidentStatus::Active);
        $otherCallSessionId = DB::table('call_sessions')->insertGetId([
            'incident_id' => $otherIncidentId,
            'citizen_id' => $otherCitizen->id,
            'status' => 'ended',
            'outcome' => 'ended_by_citizen',
            'started_at' => now()->subMinutes(3),
            'answered_at' => now()->subMinutes(2),
            'ended_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAs($operator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $incidentId,
                'reason' => 'call_dropped',
                'source_call_session_id' => $otherCallSessionId,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Source call session does not belong to this callback incident.');

        $this->assertDatabaseMissing('callback_cases', [
            'incident_id' => $incidentId,
            'source_call_session_id' => $otherCallSessionId,
        ]);
        $this->assertDatabaseCount('callback_cases', 0);
    }

    public function test_operator_can_list_callbacks_for_currently_assigned_incidents(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $otherOperator = User::factory()->create(['role' => UserRole::Operator]);
        $ownedIncidentId = $this->incident($operator, $citizen, IncidentStatus::Active);
        $otherIncidentId = $this->incident($otherOperator, $citizen, IncidentStatus::Active);

        $ownedCallbackId = $this->createCallbackCase($operator, $ownedIncidentId);
        $this->createCallbackCase($otherOperator, $otherIncidentId);

        $this->actingAs($operator)
            ->getJson('/api/operator/callbacks')
            ->assertOk()
            ->assertJsonPath('items.0.id', $ownedCallbackId)
            ->assertJsonCount(1, 'items');
    }

    public function test_callback_call_initiation_creates_reconnect_style_call_attempt(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $incidentId = $this->incident($operator, $citizen, IncidentStatus::Active);

        $callbackId = $this->actingAs($operator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $incidentId,
                'reason' => 'operator_followup',
            ])
            ->assertCreated()
            ->json('callback.id');

        $response = $this->actingAs($operator)
            ->postJson("/api/operator/callbacks/{$callbackId}/call", [
                'note' => 'Calling citizen back.',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('callback.status', 'in_progress')
            ->assertJsonPath('attempt.incident_id', $incidentId)
            ->assertJsonPath('operator_attempt.operator_id', $operator->id);

        $this->assertDatabaseHas('call_attempts', [
            'id' => $response->json('attempt.id'),
            'citizen_id' => $citizen->id,
            'incident_id' => $incidentId,
            'status' => 'calling',
        ]);

        $this->assertDatabaseHas('callback_attempts', [
            'callback_case_id' => $callbackId,
            'attempt_number' => 1,
            'call_attempt_id' => $response->json('attempt.id'),
        ]);
    }

    public function test_callback_attempt_history_records_success_and_failure(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $incidentId = $this->incident($operator, $citizen, IncidentStatus::Active);
        $callbackId = $this->createCallbackCase($operator, $incidentId);

        $attemptId = $this->actingAs($operator)
            ->postJson("/api/operator/callbacks/{$callbackId}/call")
            ->assertCreated()
            ->json('callback_attempt.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/callbacks/{$callbackId}/attempts", [
                'callback_attempt_id' => $attemptId,
                'result' => 'answered',
                'note' => 'Citizen answered.',
            ])
            ->assertOk()
            ->assertJsonPath('callback_attempt.result', 'answered');

        $this->assertDatabaseHas('callback_attempts', [
            'id' => $attemptId,
            'result' => 'answered',
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/callbacks/{$callbackId}/call")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Reconnect is already in progress for this incident.');

        $this->assertDatabaseHas('callback_attempts', [
            'callback_case_id' => $callbackId,
            'attempt_number' => 2,
            'result' => 'technical_failure',
        ]);
    }

    public function test_callback_cannot_be_completed_without_final_disposition(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $incidentId = $this->incident($operator, $citizen, IncidentStatus::Active);
        $callbackId = $this->createCallbackCase($operator, $incidentId);

        $this->actingAs($operator)
            ->postJson("/api/operator/callbacks/{$callbackId}/complete", [
                'final_disposition' => '',
            ])
            ->assertStatus(422);

        $this->actingAs($operator)
            ->postJson("/api/operator/callbacks/{$callbackId}/complete", [
                'final_disposition' => 'Citizen reached and confirmed no further call is needed.',
            ])
            ->assertOk()
            ->assertJsonPath('callback.status', 'completed');

        $this->assertDatabaseHas('callback_cases', [
            'id' => $callbackId,
            'status' => 'completed',
            'open_case_key' => null,
        ]);
    }

    public function test_incident_reassignment_changes_which_operator_can_act_on_callback(): void
    {
        [$owner, $citizen] = $this->operatorAndCitizen();
        $newOwner = User::factory()->create(['role' => UserRole::Operator]);
        $incidentId = $this->incident($owner, $citizen, IncidentStatus::Active);
        $callbackId = $this->createCallbackCase($owner, $incidentId);

        DB::table('incidents')->where('id', $incidentId)->update([
            'operator_id' => $newOwner->id,
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->postJson("/api/operator/callbacks/{$callbackId}/attempts", [
                'result' => 'no_answer',
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Operator is not assigned to this incident.');

        $this->actingAs($newOwner)
            ->postJson("/api/operator/callbacks/{$callbackId}/attempts", [
                'result' => 'no_answer',
            ])
            ->assertCreated()
            ->assertJsonPath('callback_attempt.operator_id', $newOwner->id);
    }

    public function test_normal_call_completion_does_not_auto_create_callback(): void
    {
        [$operator, $citizen] = $this->operatorAndCitizen();
        $incidentId = $this->incident($operator, $citizen, IncidentStatus::Active);
        $callSessionId = DB::table('call_sessions')->insertGetId([
            'incident_id' => $incidentId,
            'citizen_id' => $citizen->id,
            'status' => 'in_progress',
            'outcome' => 'answered',
            'started_at' => now()->subMinutes(2),
            'answered_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinute(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/call-sessions/{$callSessionId}/hangup")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseCount('callback_cases', 0);
    }

    public function test_callback_settings_defaults_are_seeded_without_overwriting_existing_values(): void
    {
        Setting::query()->create([
            'key' => 'callback_first_sla_seconds',
            'value' => ['value' => 45],
        ]);

        $this->seed(SettingsSeeder::class);

        $this->assertDatabaseHas('settings', [
            'key' => 'callback_first_sla_seconds',
        ]);
        $this->assertSame(45, Setting::query()->where('key', 'callback_first_sla_seconds')->first()->value['value']);
        $this->assertDatabaseHas('settings', [
            'key' => 'callback_max_additional_attempts',
        ]);
    }

    private function operatorAndCitizen(): array
    {
        return [
            User::factory()->create(['role' => UserRole::Operator]),
            User::factory()->create(['role' => UserRole::Citizen]),
        ];
    }

    private function incident(User $operator, User $citizen, IncidentStatus $status): int
    {
        return DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'operator_id' => $operator->id,
            'status' => $status->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'resolved_at' => in_array($status, [IncidentStatus::Resolved, IncidentStatus::Discarded], true) ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createCallbackCase(User $operator, int $incidentId): int
    {
        return $this->actingAs($operator)
            ->postJson('/api/operator/callbacks', [
                'incident_id' => $incidentId,
                'reason' => 'operator_followup',
            ])
            ->assertCreated()
            ->json('callback.id');
    }
}
