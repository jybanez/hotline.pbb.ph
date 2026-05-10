<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function test_citizen_actual_fields_win_when_legacy_caller_aliases_conflict(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/actual-citizen", [
                'actual_citizen_name' => 'Citizen Canonical',
                'actual_citizen_relationship' => 'Neighbor',
                'actual_caller_name' => 'Legacy Caller',
                'actual_caller_relationship' => 'Brother',
            ])
            ->assertOk()
            ->assertJsonPath('incident.actual_citizen_name', 'Citizen Canonical')
            ->assertJsonPath('incident.actual_citizen_relationship', 'Neighbor')
            ->assertJsonPath('incident.actual_caller_name', 'Citizen Canonical')
            ->assertJsonPath('incident.actual_caller_relationship', 'Neighbor');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'actual_caller_name' => 'Citizen Canonical',
            'actual_caller_relationship' => 'Neighbor',
        ]);
    }

    public function test_operator_can_use_citizen_named_incident_aliases(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/actual-citizen", [
                'actual_citizen_name' => 'Juan Dela Cruz',
                'actual_citizen_relationship' => 'Brother',
            ])
            ->assertOk()
            ->assertJsonPath('incident.actual_citizen_name', 'Juan Dela Cruz')
            ->assertJsonPath('incident.actual_citizen_relationship', 'Brother')
            ->assertJsonPath('incident.actual_caller_name', 'Juan Dela Cruz')
            ->assertJsonPath('incident.actual_caller_relationship', 'Brother');

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/citizen-address", [
                'location' => 'Sitio Riverside, Barangay Guadalupe, Cebu City, Philippines',
                'location_road' => 'Riverside Road',
                'location_barangay' => 'Guadalupe',
                'location_citymunicipality' => 'Cebu City',
                'location_country' => 'Philippines',
            ])
            ->assertOk()
            ->assertJsonPath('incident.location_road', 'Riverside Road')
            ->assertJsonPath('incident.location_barangay', 'Guadalupe');

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/citizen-location", [
                'latitude' => 10.3157,
                'longitude' => 123.8854,
                'accuracy' => 16,
                'source' => 'test',
            ])
            ->assertOk()
            ->assertJsonPath('incident.citizen_location.latitude', 10.3157)
            ->assertJsonPath('incident.citizen_location.longitude', 123.8854)
            ->assertJsonPath('incident.caller_location.latitude', 10.3157)
            ->assertJsonPath('incident.caller_location.longitude', 123.8854);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}/citizen-locations")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.latitude', 10.3157)
            ->assertJsonPath('items.0.longitude', 123.8854);

        $this->assertDatabaseHas('incident_caller_locations', [
            'incident_id' => $incidentId,
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
        ]);
    }

    public function test_legacy_caller_operator_routes_are_logged(): void
    {
        Log::spy();

        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/caller-address", [
                'location_barangay' => 'Guadalupe',
            ])
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->with('Hotline legacy caller route used.', \Mockery::on(
                fn (array $context): bool => ($context['contract'] ?? null) === 'operator.caller-address'
                    && ($context['method'] ?? null) === 'POST'
                    && ($context['path'] ?? null) === "api/operator/incidents/{$incidentId}/caller-address"
                    && (int) ($context['user_id'] ?? 0) === (int) $operator->id
                    && ($context['user_role'] ?? null) === UserRole::Operator->value
            ));
    }

    public function test_operator_incident_payload_includes_citizen_aliases(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $citizen->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'caller_location_accuracy' => 12,
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->getJson('/api/operator/incidents')
            ->assertOk()
            ->assertJsonPath('items.0.citizen_id', $citizen->id)
            ->assertJsonPath('items.0.caller_id', $citizen->id)
            ->assertJsonPath('items.0.actual_citizen_name', 'Maria Santos')
            ->assertJsonPath('items.0.actual_caller_name', 'Maria Santos')
            ->assertJsonPath('items.0.citizen_location.latitude', 10.3157)
            ->assertJsonPath('items.0.caller_location.latitude', 10.3157);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('citizen_id', $citizen->id)
            ->assertJsonPath('caller_id', $citizen->id)
            ->assertJsonPath('citizen.id', $citizen->id)
            ->assertJsonPath('caller.id', $citizen->id)
            ->assertJsonPath('actual_citizen_name', 'Maria Santos')
            ->assertJsonPath('actual_caller_name', 'Maria Santos')
            ->assertJsonPath('actual_citizen_relationship', 'Self')
            ->assertJsonPath('actual_caller_relationship', 'Self')
            ->assertJsonPath('citizen_location.latitude', 10.3157)
            ->assertJsonPath('caller_location.latitude', 10.3157);
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

    public function test_intake_prefers_non_empty_citizen_fields_over_legacy_caller_aliases(): void
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
                'actual_citizen_name' => 'Canonical Intake',
                'actual_citizen_relationship' => 'Witness',
                'actual_caller_name' => 'Legacy Intake',
                'actual_caller_relationship' => 'Sibling',
                'location_barangay' => 'Guadalupe',
            ])
            ->assertOk()
            ->assertJsonPath('incident.actual_citizen_name', 'Canonical Intake')
            ->assertJsonPath('incident.actual_caller_name', 'Canonical Intake')
            ->assertJsonPath('incident.actual_citizen_relationship', 'Witness')
            ->assertJsonPath('incident.actual_caller_relationship', 'Witness')
            ->assertJsonPath('incident.location_barangay', 'Guadalupe');

        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'actual_caller_name' => 'Canonical Intake',
            'actual_caller_relationship' => 'Witness',
            'location_barangay' => 'Guadalupe',
        ]);
    }
}
