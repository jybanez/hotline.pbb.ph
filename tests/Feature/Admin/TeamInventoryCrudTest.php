<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamInventoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_view_team_inventory(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => DB::table('team_categories')->insertGetId([
                'name' => 'Medical',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Medical Team One',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => DB::table('resource_type_categories')->insertGetId([
                'name' => 'Medical',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Ambulance',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->actingAs($admin)->postJson("/api/admin/teams/{$teamId}/inventories", [
            'resource_type_id' => $resourceTypeId,
            'quantity_available' => 3,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('inventory.resource_name', 'Ambulance')
            ->assertJsonPath('inventory.quantity_available', 3);

        $inventoryId = $create->json('inventory.id');

        $this->actingAs($admin)
            ->postJson("/api/admin/teams/{$teamId}/inventories/{$inventoryId}", [
                'resource_type_id' => $resourceTypeId,
                'quantity_available' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('inventory.quantity_available', 5);

        $this->actingAs($admin)
            ->getJson("/api/admin/teams/{$teamId}")
            ->assertOk()
            ->assertJsonPath('team.name', 'Medical Team One')
            ->assertJsonPath('inventories.0.resource_name', 'Ambulance')
            ->assertJsonPath('inventories.0.quantity_available', 5)
            ->assertJsonPath('resource_type_options.0.name', 'Ambulance');
    }

    public function test_admin_cannot_duplicate_resource_type_within_same_team_inventory(): void
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

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => DB::table('resource_type_categories')->insertGetId([
                'name' => 'Rescue',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Rope',
            'unit_label' => 'coil',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('team_resource_inventories')->insert([
            'team_id' => $teamId,
            'resource_type_id' => $resourceTypeId,
            'quantity_available' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/teams/{$teamId}/inventories", [
                'resource_type_id' => $resourceTypeId,
                'quantity_available' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resource_type_id']);
    }

    public function test_admin_can_delete_team_inventory(): void
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

        $inventoryId = DB::table('team_resource_inventories')->insertGetId([
            'team_id' => $teamId,
            'resource_type_id' => DB::table('resource_types')->insertGetId([
                'category_id' => DB::table('resource_type_categories')->insertGetId([
                    'name' => 'Supply',
                    'description' => null,
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'name' => 'Water',
                'unit_label' => 'box',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'quantity_available' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/teams/{$teamId}/inventories/{$inventoryId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('team_resource_inventories', [
            'id' => $inventoryId,
        ]);
    }
}
