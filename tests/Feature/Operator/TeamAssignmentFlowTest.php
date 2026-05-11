<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamAssignmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_create_update_and_delete_assigned_team_assignment(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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

        $resourceTypeCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Vehicle',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceTypeCategoryId,
            'name' => 'Ambulance',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $createResponse = $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/team-assignments", [
                'team_id' => $teamId,
                'contact_person' => 'Chief Ramos',
                'resources' => [
                    [
                        'resource_type_id' => $resourceTypeId,
                        'quantity_allocated' => 2,
                    ],
                ],
            ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.status', 'assigned')
            ->assertJsonPath('assignment.allocated_resources.0.quantity_allocated', 2);

        $assignmentId = $createResponse->json('assignment.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/team-assignments/{$assignmentId}", [
                'status' => 'Accepted',
                'contact_person' => 'Chief Ramos',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.status', 'accepted');

        $this->actingAs($operator)
            ->deleteJson("/api/operator/team-assignments/{$assignmentId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->actingAs($operator)
            ->postJson("/api/operator/team-assignments/{$assignmentId}", [
                'status' => 'Cancelled',
                'cancel_reason_code' => 'other',
                'cancel_reason_note' => 'Duplicate dispatch acknowledged by command.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.status', 'cancelled')
            ->assertJsonPath('assignment.cancel_reason_note', 'Duplicate dispatch acknowledged by command.');
    }

    public function test_operator_can_add_team_assignment_note(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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

        $assignmentId = $this->actingAs($operator)
            ->postJson("/api/operator/incidents/{$incidentId}/team-assignments", [
                'team_id' => $teamId,
                'contact_person' => 'Chief Ramos',
                'resources' => [],
            ])
            ->assertCreated()
            ->json('assignment.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/team-assignments/{$assignmentId}/notes", [
                'note' => 'Team confirmed dispatch and is preparing to depart.',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('assignment.notes.0.note', 'Team confirmed dispatch and is preparing to depart.')
            ->assertJsonPath('assignment.notes.0.created_by_operator_id', $operator->id);

        $this->assertDatabaseHas('team_assignment_notes', [
            'team_assignment_id' => $assignmentId,
            'created_by_operator_id' => $operator->id,
            'note' => 'Team confirmed dispatch and is preparing to depart.',
        ]);
    }
}
