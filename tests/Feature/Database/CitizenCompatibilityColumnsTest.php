<?php

namespace Tests\Feature\Database;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Incidents\Models\IncidentCallerLocation;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
