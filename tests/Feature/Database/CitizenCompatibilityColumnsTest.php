<?php

namespace Tests\Feature\Database;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentCitizenLocation;
use App\Domain\Shared\Concerns\SynchronizesCitizenIdentity;
use App\Domain\Shared\Concerns\SynchronizesCitizenIncidentDetails;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CitizenCompatibilityColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_caller_storage_columns_and_tables_are_removed_after_5f(): void
    {
        foreach (['incidents', 'call_attempts', 'call_sessions'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'citizen_id'), "{$table} is missing citizen_id");
            $this->assertFalse(Schema::hasColumn($table, 'caller_id'), "{$table} still has caller_id");
        }

        foreach ([
            'actual_caller_name',
            'actual_caller_relationship',
            'caller_location_accuracy',
            'caller_altitude',
            'caller_altitude_accuracy',
            'caller_heading',
            'caller_heading_source',
            'caller_location_captured_at',
        ] as $column) {
            $this->assertFalse(Schema::hasColumn('incidents', $column), "incidents still has {$column}");
        }

        $this->assertFalse(Schema::hasTable('incident_caller_locations'));
        $this->assertTrue(Schema::hasTable('incident_citizen_locations'));
        $this->assertTrue(Schema::hasColumn('incident_citizen_locations', 'citizen_id'));
        $this->assertFalse(Schema::hasColumn('incident_citizen_locations', 'caller_id'));
    }

    public function test_models_expose_legacy_accessors_from_citizen_storage(): void
    {
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $capturedAt = now()->subMinute()->startOfSecond();

        $incident = Incident::query()->create([
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

        $attempt = CallAttempt::query()->create([
            'citizen_id' => $citizen->id,
            'incident_id' => $incident->id,
            'answered_by_operator_id' => $operator->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
        ]);

        $session = CallSession::query()->create([
            'incident_id' => $incident->id,
            'citizen_id' => $citizen->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
            'answered_at' => now(),
        ]);

        $this->assertSame($citizen->id, $incident->citizen_id);
        $this->assertSame($citizen->id, $incident->caller_id);
        $this->assertSame('Canonical Citizen Name', $incident->actual_citizen_name);
        $this->assertSame('Canonical Citizen Name', $incident->actual_caller_name);
        $this->assertSame('Self', $incident->actual_caller_relationship);
        $this->assertSame(12.0, (float) $incident->caller_location_accuracy);
        $this->assertSame(20.0, (float) $incident->caller_altitude);
        $this->assertSame(3.0, (float) $incident->caller_altitude_accuracy);
        $this->assertSame(45.0, (float) $incident->caller_heading);
        $this->assertSame('gps', $incident->caller_heading_source);
        $this->assertTrue($incident->caller_location_captured_at->equalTo($capturedAt));
        $this->assertSame($citizen->id, $attempt->caller_id);
        $this->assertSame($citizen->id, $session->caller_id);
    }

    public function test_incident_citizen_locations_store_citizen_history_without_caller_id(): void
    {
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $incident = Incident::query()->create([
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
    }

    public function test_citizen_incident_api_filters_by_citizen_id(): void
    {
        $otherCitizen = User::factory()->create(['role' => UserRole::Citizen]);
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $incident = Incident::query()->create([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
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

        $this->actingAs($otherCitizen)
            ->getJson('/api/citizen/incidents/current')
            ->assertOk()
            ->assertJsonPath('incident', null);
    }

    public function test_citizen_sync_traits_ignore_columns_missing_from_legacy_schema(): void
    {
        Schema::create('citizen_sync_probe', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('citizen_id')->nullable();
            $table->string('actual_citizen_name')->nullable();
            $table->string('actual_citizen_relationship')->nullable();
            $table->decimal('citizen_location_accuracy', 10, 2)->nullable();
        });

        try {
            $model = new class extends Model
            {
                use SynchronizesCitizenIdentity;
                use SynchronizesCitizenIncidentDetails;

                protected $table = 'citizen_sync_probe';

                public $timestamps = false;

                protected $guarded = [];
            };

            $created = $model->newQuery()->create([
                'citizen_id' => 123,
                'citizen_id' => 123,
                'actual_citizen_name' => 'Legacy Caller',
                'actual_citizen_name' => 'Canonical Citizen',
                'actual_citizen_relationship' => 'Legacy Self',
                'actual_citizen_relationship' => 'Self',
                'citizen_location_accuracy' => 99,
                'citizen_location_accuracy' => 8,
            ]);

            $row = DB::table('citizen_sync_probe')->where('id', $created->getKey())->first();

            $this->assertSame(123, (int) $row->citizen_id);
            $this->assertSame('Canonical Citizen', $row->actual_citizen_name);
            $this->assertSame('Self', $row->actual_citizen_relationship);
            $this->assertSame('8', (string) $row->citizen_location_accuracy);
        } finally {
            Schema::dropIfExists('citizen_sync_probe');
        }
    }

    public function test_protocol_value_migration_converts_legacy_caller_values_to_citizen_values(): void
    {
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $incident = Incident::query()->create([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $attempt = CallAttempt::query()->create([
            'citizen_id' => $citizen->id,
            'incident_id' => $incident->id,
            'answered_by_operator_id' => $operator->id,
            'status' => CallStatus::Ended,
            'outcome' => 'cancelled_by_caller',
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        $session = CallSession::query()->create([
            'incident_id' => $incident->id,
            'citizen_id' => $citizen->id,
            'status' => CallStatus::Ended,
            'outcome' => 'ended_by_caller',
            'started_at' => now(),
            'answered_at' => now(),
            'ended_at' => now(),
        ]);

        DB::table('call_participants')->insert([
            'call_session_id' => $session->id,
            'user_id' => $citizen->id,
            'participant_role' => 'caller',
            'joined_at' => now(),
            'created_at' => now(),
        ]);

        DB::table('media')->insert([
            'incident_id' => $incident->id,
            'call_session_id' => $session->id,
            'type' => 'caller_video',
            'peer_user_id' => $citizen->id,
            'peer_role' => 'caller',
            'peer_label' => $citizen->name,
            'path' => 'media/caller-video.mp4',
            'created_at' => now(),
            'available_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_05_11_000003_migrate_caller_protocol_values_to_citizen.php');
        $migration->up();

        $this->assertDatabaseHas('call_attempts', [
            'id' => $attempt->id,
            'outcome' => 'cancelled_by_citizen',
        ]);
        $this->assertDatabaseHas('call_sessions', [
            'id' => $session->id,
            'outcome' => 'ended_by_citizen',
        ]);
        $this->assertDatabaseHas('call_participants', [
            'call_session_id' => $session->id,
            'user_id' => $citizen->id,
            'participant_role' => 'citizen',
        ]);
        $this->assertDatabaseHas('media', [
            'incident_id' => $incident->id,
            'call_session_id' => $session->id,
            'type' => 'citizen_video',
            'peer_role' => 'citizen',
        ]);
    }
}
