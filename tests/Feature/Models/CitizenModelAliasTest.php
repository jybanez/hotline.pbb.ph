<?php

namespace Tests\Feature\Models;

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
use Tests\TestCase;

class CitizenModelAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_exposes_citizen_aliases_over_caller_storage(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'caller_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        IncidentCallerLocation::query()->create([
            'incident_id' => $incident->id,
            'caller_id' => $citizen->id,
            'citizen_id' => $citizen->id,
            'operator_id' => $operator->id,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'captured_at' => now(),
            'received_at' => now(),
        ]);

        IncidentCitizenLocation::query()->create([
            'incident_id' => $incident->id,
            'citizen_id' => $citizen->id,
            'operator_id' => $operator->id,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'captured_at' => now(),
            'received_at' => now(),
        ]);

        $incident->refresh();

        $this->assertSame($citizen->id, $incident->citizen_id);
        $this->assertSame($citizen->id, $incident->citizen()->first()?->id);
        $this->assertSame($citizen->id, $incident->caller()->first()?->id);
        $this->assertSame(1, $incident->citizenLocations()->count());
        $this->assertSame(1, $incident->callerLocations()->count());
    }

    public function test_call_models_expose_citizen_aliases_over_caller_storage(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'caller_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $attempt = CallAttempt::query()->create([
            'caller_id' => $citizen->id,
            'incident_id' => $incident->id,
            'answered_by_operator_id' => $operator->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
        ]);

        $session = CallSession::query()->create([
            'incident_id' => $incident->id,
            'caller_id' => $citizen->id,
            'status' => CallStatus::InProgress,
            'outcome' => CallOutcome::Answered,
            'started_at' => now(),
            'answered_at' => now(),
        ]);

        $this->assertSame($citizen->id, $attempt->citizen_id);
        $this->assertSame($citizen->id, $attempt->citizen()->first()?->id);
        $this->assertSame($citizen->id, $attempt->caller()->first()?->id);
        $this->assertSame($citizen->id, $session->citizen_id);
        $this->assertSame($citizen->id, $session->citizen()->first()?->id);
        $this->assertSame($citizen->id, $session->caller()->first()?->id);
    }

    public function test_incident_location_exposes_citizen_alias_over_caller_storage(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incident = Incident::query()->create([
            'caller_id' => $citizen->id,
            'actual_caller_name' => $citizen->name,
            'actual_caller_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active,
            'alert_level' => AlertLevel::Normal,
            'called_at' => now(),
        ]);

        $location = IncidentCallerLocation::query()->create([
            'incident_id' => $incident->id,
            'caller_id' => $citizen->id,
            'operator_id' => $operator->id,
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'captured_at' => now(),
            'received_at' => now(),
        ]);

        $this->assertSame($citizen->id, $location->citizen_id);
        $this->assertSame($citizen->id, $location->citizen()->first()?->id);
        $this->assertSame($citizen->id, $location->caller()->first()?->id);
    }
}
