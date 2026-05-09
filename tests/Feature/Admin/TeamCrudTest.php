<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_list_teams(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/teams', [
            'team_category_id' => $categoryId,
            'name' => 'Medical Team One',
            'status' => 'active',
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('team.name', 'Medical Team One')
            ->assertJsonPath('team.category_name', 'Medical');

        $teamId = $create->json('team.id');

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Medical',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Ambulance',
            'unit_label' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('team_resource_inventories')->insert([
            'team_id' => $teamId,
            'resource_type_id' => $resourceTypeId,
            'quantity_available' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/teams/{$teamId}", [
                'team_category_id' => $categoryId,
                'name' => 'Medical Team Prime',
                'status' => 'standby',
            ])
            ->assertOk()
            ->assertJsonPath('team.name', 'Medical Team Prime')
            ->assertJsonPath('team.status', 'standby');

        $this->actingAs($admin)
            ->getJson('/api/admin/teams')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Medical Team Prime')
            ->assertJsonPath('items.0.inventory_count', 1);
    }

    public function test_admin_delete_is_blocked_when_team_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => DB::table('team_categories')->insertGetId([
                'name' => 'Rescue',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Rescue Team Alpha',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('team_resource_inventories')->insert([
            'team_id' => $teamId,
            'resource_type_id' => DB::table('resource_types')->insertGetId([
                'category_id' => DB::table('resource_type_categories')->insertGetId([
                    'name' => 'Equipment',
                    'description' => null,
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'name' => 'Generator',
                'unit_label' => 'unit',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'quantity_available' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/teams/{$teamId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'team_resource_inventories');
    }

    public function test_admin_can_hard_delete_unreferenced_team(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => DB::table('team_categories')->insertGetId([
                'name' => 'Support',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Support Team',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/teams/{$teamId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('teams', [
            'id' => $teamId,
        ]);
    }
}
