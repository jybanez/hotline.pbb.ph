<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResourceTypeCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_list_resource_type_categories(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/resource-type-categories', [
            'name' => 'Supply',
            'description' => 'Consumable supplies',
            'sort_order' => 2,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('category.name', 'Supply');

        $categoryId = $create->json('category.id');

        $this->actingAs($admin)
            ->postJson("/api/admin/resource-type-categories/{$categoryId}", [
                'name' => 'Supplies',
                'description' => 'Updated description',
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('category.name', 'Supplies')
            ->assertJsonPath('category.sort_order', 1);

        $this->actingAs($admin)
            ->getJson('/api/admin/resource-type-categories')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Supplies');
    }

    public function test_admin_delete_is_blocked_when_category_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Equipment',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('resource_types')->insert([
            'category_id' => $categoryId,
            'name' => 'Generator',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/resource-type-categories/{$categoryId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'resource_types');
    }

    public function test_admin_can_hard_delete_unreferenced_resource_type_category(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Vehicle',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/resource-type-categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('resource_type_categories', [
            'id' => $categoryId,
        ]);
    }
}
