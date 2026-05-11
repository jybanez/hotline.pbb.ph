<?php

namespace Tests\Feature\Command;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommandIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_command_incident_list_omits_legacy_caller_aliases(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $command = User::factory()->create([
            'role' => UserRole::Command,
        ]);

        DB::table('incidents')->insert([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => 'Maria Santos',
            'actual_citizen_relationship' => 'Self',
            'operator_id' => User::factory()->create(['role' => UserRole::Operator])->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'citizen_location_accuracy' => 12,
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($command)
            ->getJson('/api/command/incidents')
            ->assertOk()
            ->assertJsonPath('items.0.citizen_id', $citizen->id)
            ->assertJsonPath('items.0.actual_citizen_name', 'Maria Santos')
            ->assertJsonPath('items.0.citizen_name', 'Maria Santos')
            ->assertJsonPath('items.0.citizen_location.latitude', 10.3157)
            ->assertJsonMissingPath('items.0.caller_id')
            ->assertJsonMissingPath('items.0.actual_caller_name')
            ->assertJsonMissingPath('items.0.caller_name')
            ->assertJsonMissingPath('items.0.caller_location');
    }
}
