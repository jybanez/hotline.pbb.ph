<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_dashboard_reports_engaged_when_operator_has_active_incident(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        DB::table('incidents')->insert([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->getJson('/api/operator/dashboard')
            ->assertOk()
            ->assertJsonPath('operator_runtime_state', 'engaged')
            ->assertJsonPath('stat_chips.3.label', 'Incoming')
            ->assertJsonPath('stat_chips.3.value', 0)
            ->assertJsonPath('stat_chips.0.label', 'State');
    }

    public function test_operator_dashboard_exposes_transfer_targets_pending_transfers_and_incoming_calls(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $currentOperator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $availableOperator = User::factory()->create([
            'role' => UserRole::Operator,
            'name' => 'Transfer Target',
        ]);

        $requestingOperator = User::factory()->create([
            'role' => UserRole::Operator,
            'name' => 'Requesting Operator',
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $currentOperator->id,
            'status' => IncidentStatus::Deferred->value,
            'alert_level' => 'Normal',
            'called_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('call_sessions')->insert([
            'incident_id' => $incidentId,
            'citizen_id' => $caller->id,
            'status' => 'calling',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_transfers')->insert([
            'incident_id' => $incidentId,
            'from_operator_id' => $requestingOperator->id,
            'to_operator_id' => $currentOperator->id,
            'reason' => 'Please take this incident.',
            'status' => 'requested',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($currentOperator)
            ->getJson('/api/operator/dashboard')
            ->assertOk()
            ->assertJsonPath('stat_chips.3.label', 'Incoming')
            ->assertJsonPath('stat_chips.3.value', 1)
            ->assertJsonPath('pending_transfer_requests.0.reason', 'Please take this incident.')
            ->assertJsonFragment(['name' => 'Transfer Target']);
    }

    public function test_operator_dashboard_exposes_recent_operator_activity_items(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $otherOperator = User::factory()->create([
            'role' => UserRole::Operator,
            'name' => 'Partner Operator',
        ]);

        $teamCategoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Response',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => $teamCategoryId,
            'name' => 'Rescue Team',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Deferred->value,
            'alert_level' => 'Normal',
            'called_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(2),
        ]);

        DB::table('incident_transfers')->insert([
            'incident_id' => $incidentId,
            'from_operator_id' => $operator->id,
            'to_operator_id' => $otherOperator->id,
            'reason' => 'Handing off coverage.',
            'status' => 'requested',
            'requested_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        DB::table('team_assignments')->insert([
            'incident_id' => $incidentId,
            'team_id' => $teamId,
            'assigned_by_operator_id' => $operator->id,
            'status' => 'Assigned',
            'assigned_at' => now()->subSeconds(30),
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        $this->actingAs($operator)
            ->getJson('/api/operator/dashboard')
            ->assertOk()
            ->assertJsonPath('team_assignment_lanes.0.id', 'assigned')
            ->assertJsonPath('team_assignment_lanes.2.id', 'accepted');

        $this->actingAs($operator)
            ->getJson('/api/operator/activity')
            ->assertOk()
            ->assertJsonPath('items.0.kind', 'team_assignment')
            ->assertJsonPath('items.0.incident_id', $incidentId)
            ->assertJsonFragment(['title' => 'Transfer for #'.str_pad((string) $incidentId, 6, '0', STR_PAD_LEFT)])
            ->assertJsonFragment(['title' => 'Incident #'.str_pad((string) $incidentId, 6, '0', STR_PAD_LEFT)]);

        $this->actingAs($operator)
            ->getJson('/api/operator/incidents?status=Active,Deferred')
            ->assertOk()
            ->assertJsonPath('items.0.team_assignments.0.incident_id', $incidentId);
    }

    public function test_citizen_home_turns_yellow_when_no_operators_are_available(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        DB::table('incidents')->insert([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($citizen)
            ->getJson('/api/citizen/home')
            ->assertOk()
            ->assertJsonPath('availability.status', 'yellow')
            ->assertJsonPath('availability.available_operator_count', 0);
    }
}
