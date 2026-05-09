<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResourceTypeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_list_resource_types(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Supply',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/resource-types', [
            'name' => 'Water',
            'category_id' => $categoryId,
            'unit_label' => 'gallons',
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('resource_type.name', 'Water');

        $resourceTypeId = $create->json('resource_type.id');

        $this->actingAs($admin)
            ->postJson("/api/admin/resource-types/{$resourceTypeId}", [
                'name' => 'Water Container',
                'category_id' => $categoryId,
                'unit_label' => 'units',
            ])
            ->assertOk()
            ->assertJsonPath('resource_type.name', 'Water Container')
            ->assertJsonPath('resource_type.unit_label', 'units');

        $this->actingAs($admin)
            ->getJson('/api/admin/resource-types')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Water Container');
    }

    public function test_admin_delete_is_blocked_when_resource_type_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => DB::table('resource_type_categories')->insertGetId([
                'name' => 'Equipment',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Generator',
            'unit_label' => 'units',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamCategoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Medical',
            'description' => 'Medical response teams',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('team_resource_inventories')->insert([
            'team_id' => DB::table('teams')->insertGetId([
                'team_category_id' => $teamCategoryId,
                'name' => 'Response Team 1',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'resource_type_id' => $resourceTypeId,
            'quantity_available' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/resource-types/{$resourceTypeId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'team_resource_inventories');
    }

    public function test_admin_can_hard_delete_unreferenced_resource_type(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => DB::table('resource_type_categories')->insertGetId([
                'name' => 'Supply',
                'description' => null,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'name' => 'Bottled Water',
            'unit_label' => 'boxes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/resource-types/{$resourceTypeId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('resource_types', [
            'id' => $resourceTypeId,
        ]);
    }
}
