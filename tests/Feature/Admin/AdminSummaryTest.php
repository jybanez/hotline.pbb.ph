<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_summary_counts_are_backed_by_phase_one_tables(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentCategoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_types')->insert([
            'incident_category_id' => $incidentCategoryId,
            'name' => 'Medical Emergency',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamCategoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Response',
            'description' => null,
            'sort_order' => 1,
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

        DB::table('resource_types')->insert([
            'category_id' => $resourceTypeCategoryId,
            'name' => 'Ambulance',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('teams')->insert([
            'team_category_id' => $teamCategoryId,
            'name' => 'Team One',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/summary')
            ->assertOk()
            ->assertJsonPath('counts.teams', 1)
            ->assertJsonPath('counts.incident_types', 1)
            ->assertJsonPath('counts.resource_types', 1)
            ->assertJsonPath('counts.operators', 1);
    }
}
