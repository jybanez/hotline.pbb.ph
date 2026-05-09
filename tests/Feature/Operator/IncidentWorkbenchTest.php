<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_list_and_open_owned_incident_workbench(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'location' => 'Guadalupe, Cebu City',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->getJson('/api/operator/incidents')
            ->assertOk()
            ->assertJsonPath('items.0.id', $incidentId)
            ->assertJsonPath('items.0.display_id', str_pad((string) $incidentId, 6, '0', STR_PAD_LEFT));

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('id', $incidentId)
            ->assertJsonPath('caller.id', $caller->id)
            ->assertJsonPath('status', 'Active');
    }

    public function test_operator_incident_payload_includes_current_call_session(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => 'Maria Santos',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('call_sessions')->insert([
            [
                'id' => 1001,
                'incident_id' => $incidentId,
                'caller_id' => $caller->id,
                'status' => 'ended',
                'outcome' => 'ended_by_caller',
                'started_at' => now()->subMinutes(3),
                'answered_at' => now()->subMinutes(3),
                'ended_at' => now()->subMinutes(2),
                'created_at' => now()->subMinutes(3),
                'updated_at' => now()->subMinutes(2),
            ],
            [
                'id' => 1002,
                'incident_id' => $incidentId,
                'caller_id' => $caller->id,
                'status' => 'in_progress',
                'outcome' => null,
                'started_at' => now()->subSeconds(20),
                'answered_at' => now()->subSeconds(15),
                'ended_at' => null,
                'created_at' => now()->subSeconds(20),
                'updated_at' => now()->subSeconds(10),
            ],
        ]);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('current_call_session.id', 1002)
            ->assertJsonPath('current_call_session.status', 'in_progress');
    }

    public function test_operator_cannot_open_another_operators_incident(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $owner = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $otherOperator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => 'Maria Santos',
            'operator_id' => $owner->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($otherOperator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertNotFound();
    }

    public function test_resolve_is_blocked_when_team_assignments_are_still_open(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $teamCategoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Response',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => $teamCategoryId,
            'name' => 'Team One',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => 'Maria Santos',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('team_assignments')->insert([
            'incident_id' => $incidentId,
            'team_id' => $teamId,
            'assigned_by_operator_id' => $operator->id,
            'status' => 'Assigned',
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/status", [
                'status' => 'Resolved',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);
    }

    public function test_operator_can_defer_or_resolve_incident_when_rules_allow(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => 'Maria Santos',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/status", [
                'status' => 'Deferred',
            ])
            ->assertOk()
            ->assertJsonPath('incident.status', 'Deferred');

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/status", [
                'status' => 'Resolved',
            ])
            ->assertOk()
            ->assertJsonPath('incident.status', 'Resolved');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'status' => 'Resolved',
        ]);
    }

    public function test_operator_can_update_actual_caller_fields(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/actual-caller", [
                'actual_caller_name' => 'Juan Dela Cruz',
                'actual_caller_relationship' => 'Brother',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('incident.actual_caller_name', 'Juan Dela Cruz')
            ->assertJsonPath('incident.actual_caller_relationship', 'Brother');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'actual_caller_name' => 'Juan Dela Cruz',
            'actual_caller_relationship' => 'Brother',
        ]);
    }

    public function test_operator_can_update_caller_address_fields(): void
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

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/caller-address", [
                'location' => 'Sitio Riverside, Barangay Guadalupe, Cebu City, Philippines',
                'location_road' => 'Riverside Road',
                'location_suburb' => 'Sitio Riverside',
                'location_barangay' => 'Guadalupe',
                'location_citymunicipality' => 'Cebu City',
                'location_country' => 'Philippines',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('incident.location', 'Sitio Riverside, Barangay Guadalupe, Cebu City, Philippines')
            ->assertJsonPath('incident.location_road', 'Riverside Road')
            ->assertJsonPath('incident.location_suburb', 'Sitio Riverside')
            ->assertJsonPath('incident.location_barangay', 'Guadalupe')
            ->assertJsonPath('incident.location_citymunicipality', 'Cebu City')
            ->assertJsonPath('incident.location_country', 'Philippines');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'location_road' => 'Riverside Road',
            'location_barangay' => 'Guadalupe',
            'location_citymunicipality' => 'Cebu City',
        ]);
    }

    public function test_operator_can_update_initial_intake_fields_in_one_request(): void
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
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/intake", [
                'actual_caller_name' => 'Juan Dela Cruz',
                'actual_caller_relationship' => 'Brother',
                'location' => 'Riverside Road, Sitio Riverside, Barangay Guadalupe, Cebu City, Philippines',
                'location_road' => 'Riverside Road',
                'location_suburb' => 'Sitio Riverside',
                'location_barangay' => 'Guadalupe',
                'location_citymunicipality' => 'Cebu City',
                'location_country' => 'Philippines',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('incident.actual_caller_name', 'Juan Dela Cruz')
            ->assertJsonPath('incident.actual_caller_relationship', 'Brother')
            ->assertJsonPath('incident.location_road', 'Riverside Road')
            ->assertJsonPath('incident.location_barangay', 'Guadalupe')
            ->assertJsonPath('incident.location_citymunicipality', 'Cebu City');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'actual_caller_name' => 'Juan Dela Cruz',
            'actual_caller_relationship' => 'Brother',
            'location_road' => 'Riverside Road',
            'location_barangay' => 'Guadalupe',
            'location_citymunicipality' => 'Cebu City',
        ]);
    }
}
