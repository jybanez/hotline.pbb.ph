<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_list_team_categories(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/team-categories', [
            'name' => 'Medical',
            'description' => 'Medical response teams',
            'sort_order' => 2,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('category.name', 'Medical');

        $categoryId = $create->json('category.id');

        $this->actingAs($admin)
            ->postJson("/api/admin/team-categories/{$categoryId}", [
                'name' => 'Medical Response',
                'description' => 'Updated description',
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('category.name', 'Medical Response')
            ->assertJsonPath('category.sort_order', 1);

        $this->actingAs($admin)
            ->getJson('/api/admin/team-categories')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Medical Response');
    }

    public function test_admin_delete_is_blocked_when_team_category_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Rescue',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('teams')->insert([
            'team_category_id' => $categoryId,
            'name' => 'Rescue Team Alpha',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/team-categories/{$categoryId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'teams');
    }

    public function test_admin_can_hard_delete_unreferenced_team_category(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Support',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/team-categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('team_categories', [
            'id' => $categoryId,
        ]);
    }
}
