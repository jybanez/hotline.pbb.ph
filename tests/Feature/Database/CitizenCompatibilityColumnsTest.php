<?php

namespace Tests\Feature\Database;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentCallerLocation;
use App\Domain\Incidents\Models\IncidentCitizenLocation;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CitizenCompatibilityColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_id_columns_exist_beside_caller_id_columns(): void
    {
        foreach (['incidents', 'call_attempts', 'call_sessions', 'incident_caller_locations'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'caller_id'), "{$table} is missing caller_id");
            $this->assertTrue(Schema::hasColumn($table, 'citizen_id'), "{$table} is missing citizen_id");
        }

        $this->assertTrue(Schema::hasTable('incident_citizen_locations'));
        $this->assertFalse(Schema::hasColumn('incident_citizen_locations', 'caller_id'));
        $this->assertTrue(Schema::hasColumn('incident_citizen_locations', 'citizen_id'));
    }

    public function test_citizen_detail_columns_exist_beside_caller_detail_columns(): void
    {
        foreach ([
            'actual_citizen_name',
            'actual_citizen_relationship',
            'citizen_location_accuracy',
            'citizen_altitude',
            'citizen_altitude_accuracy',
            'citizen_heading',
            'citizen_heading_source',
            'citizen_location_captured_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('incidents', $column), "incidents is missing {$column}");
        }
    }

    public function test_models_can_read_citizen_id_from_new_compatibility_columns(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $attempt = CallAttempt::query()->create([
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'incident_id' => $incident->id,
            'answered_by_operator_id' => $operator->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
        ]);

        $session = CallSession::query()->create([
            'incident_id' => $incident->id,
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
            'answered_at' => now(),
        ]);

        $location = IncidentCallerLocation::query()->create([
            'incident_id' => $incident->id,
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'operator_id' => $operator->id,
            'call_session_id' => $session->id,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'captured_at' => now(),
            'received_at' => now(),
        ]);

        $this->assertSame($citizen->id, $incident->citizen_id);
        $this->assertSame($citizen->id, $attempt->citizen_id);
        $this->assertSame($citizen->id, $session->citizen_id);
        $this->assertSame($citizen->id, $location->citizen_id);
    }

    public function test_incident_citizen_locations_store_citizen_history_without_caller_id(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $location = IncidentCitizenLocation::query()->create([
            'incident_id' => $incident->id,
            'citizen_id' => $citizen->id,
            'operator_id' => $operator->id,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'accuracy' => 16,
            'source' => 'test',
            'captured_at' => now(),
            'received_at' => now(),
        ]);

        $this->assertSame($citizen->id, $location->citizen()->first()?->id);
        $this->assertSame(1, $incident->citizenLocations()->count());
        $this->assertSame(0, $incident->callerLocations()->count());
    }

    public function test_citizen_relationships_use_citizen_id_as_active_identity_column(): void
    {
        $legacyCaller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'caller_id' => $legacyCaller->id,
            'citizen_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $attempt = CallAttempt::query()->create([
            'caller_id' => $legacyCaller->id,
            'citizen_id' => $citizen->id,
            'incident_id' => $incident->id,
            'answered_by_operator_id' => $operator->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
        ]);

        $session = CallSession::query()->create([
            'incident_id' => $incident->id,
            'caller_id' => $legacyCaller->id,
            'citizen_id' => $citizen->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
            'answered_at' => now(),
        ]);

        $location = IncidentCallerLocation::query()->create([
            'incident_id' => $incident->id,
            'caller_id' => $legacyCaller->id,
            'citizen_id' => $citizen->id,
            'operator_id' => $operator->id,
            'call_session_id' => $session->id,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'captured_at' => now(),
            'received_at' => now(),
        ]);

        $this->assertSame($citizen->id, $incident->citizen()->first()?->id);
        $this->assertSame($legacyCaller->id, $incident->caller()->first()?->id);
        $this->assertSame($citizen->id, $attempt->citizen()->first()?->id);
        $this->assertSame($legacyCaller->id, $attempt->caller()->first()?->id);
        $this->assertSame($citizen->id, $session->citizen()->first()?->id);
        $this->assertSame($legacyCaller->id, $session->caller()->first()?->id);
        $this->assertSame($citizen->id, $location->citizen()->first()?->id);
        $this->assertSame($legacyCaller->id, $location->caller()->first()?->id);
    }

    public function test_citizen_incident_api_filters_by_citizen_id_not_caller_id(): void
    {
        $legacyCaller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'caller_id' => $legacyCaller->id,
            'citizen_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $this->actingAs($citizen)
            ->getJson('/api/citizen/incidents/current')
            ->assertOk()
            ->assertJsonPath('incident.id', $incident->id)
            ->assertJsonPath('incident.citizen_id', $citizen->id);

        $this->actingAs($legacyCaller)
            ->getJson('/api/citizen/incidents/current')
            ->assertOk()
            ->assertJsonPath('incident', null);
    }

    public function test_models_fill_missing_identity_side_for_rollback_sync(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'citizen_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $row = DB::table('incidents')->where('id', $incident->id)->first();

        $this->assertSame($citizen->id, (int) $row->caller_id);
        $this->assertSame($citizen->id, (int) $row->citizen_id);
    }

    public function test_incident_detail_accessors_prefer_citizen_columns(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);
        $capturedAt = now()->subMinute()->startOfSecond();

        $incident = Incident::query()->create([
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'actual_caller_name' => 'Legacy Caller Name',
            'actual_citizen_name' => 'Canonical Citizen Name',
            'actual_caller_relationship' => 'Legacy Relationship',
            'actual_citizen_relationship' => 'Canonical Relationship',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'caller_location_accuracy' => 99,
            'citizen_location_accuracy' => 12,
            'caller_altitude' => 300,
            'citizen_altitude' => 20,
            'caller_altitude_accuracy' => 30,
            'citizen_altitude_accuracy' => 3,
            'caller_heading' => 180,
            'citizen_heading' => 45,
            'caller_heading_source' => 'legacy',
            'citizen_heading_source' => 'gps',
            'caller_location_captured_at' => $capturedAt->copy()->subMinute(),
            'citizen_location_captured_at' => $capturedAt,
            'called_at' => now(),
        ]);

        $this->assertSame('Canonical Citizen Name', $incident->actual_citizen_name);
        $this->assertSame('Legacy Caller Name', $incident->actual_caller_name);
        $this->assertSame('Canonical Relationship', $incident->actual_citizen_relationship);
        $this->assertSame('Legacy Relationship', $incident->actual_caller_relationship);
        $this->assertSame(12.0, (float) $incident->citizen_location_accuracy);
        $this->assertSame(99.0, (float) $incident->caller_location_accuracy);
        $this->assertSame(20.0, (float) $incident->citizen_altitude);
        $this->assertSame(3.0, (float) $incident->citizen_altitude_accuracy);
        $this->assertSame(45.0, (float) $incident->citizen_heading);
        $this->assertSame('gps', $incident->citizen_heading_source);
        $this->assertTrue($incident->citizen_location_captured_at->equalTo($capturedAt));
    }

    public function test_incident_details_fill_missing_side_for_rollback_sync(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);
        $capturedAt = now()->subMinute()->startOfSecond();

        $incident = Incident::query()->create([
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => 'Canonical Citizen Name',
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'citizen_location_accuracy' => 12,
            'citizen_altitude' => 20,
            'citizen_altitude_accuracy' => 3,
            'citizen_heading' => 45,
            'citizen_heading_source' => 'gps',
            'citizen_location_captured_at' => $capturedAt,
            'called_at' => now(),
        ]);

        $row = DB::table('incidents')->where('id', $incident->id)->first();

        $this->assertSame('Canonical Citizen Name', $row->actual_caller_name);
        $this->assertSame('Canonical Citizen Name', $row->actual_citizen_name);
        $this->assertSame('Self', $row->actual_caller_relationship);
        $this->assertSame('Self', $row->actual_citizen_relationship);
        $this->assertSame('12', (string) $row->caller_location_accuracy);
        $this->assertSame('12', (string) $row->citizen_location_accuracy);
        $this->assertSame('20', (string) $row->caller_altitude);
        $this->assertSame('20', (string) $row->citizen_altitude);
        $this->assertSame('3', (string) $row->caller_altitude_accuracy);
        $this->assertSame('3', (string) $row->citizen_altitude_accuracy);
        $this->assertSame('45', (string) $row->caller_heading);
        $this->assertSame('45', (string) $row->citizen_heading);
        $this->assertSame('gps', $row->caller_heading_source);
        $this->assertSame('gps', $row->citizen_heading_source);
    }
}
