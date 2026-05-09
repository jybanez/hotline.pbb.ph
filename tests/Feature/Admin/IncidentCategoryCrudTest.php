<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_update_and_list_incident_categories(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/incident-categories', [
            'name' => 'Medical',
            'description' => 'Medical events',
            'sort_order' => 2,
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('category.name', 'Medical');

        $categoryId = $create->json('category.id');

        $this->actingAs($admin)
            ->postJson("/api/admin/incident-categories/{$categoryId}", [
                'name' => 'Medical Emergency',
                'description' => 'Updated description',
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('category.name', 'Medical Emergency')
            ->assertJsonPath('category.sort_order', 1);

        $this->actingAs($admin)
            ->getJson('/api/admin/incident-categories')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Medical Emergency');
    }

    public function test_admin_delete_is_blocked_when_incident_category_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Flood',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_types')->insert([
            'incident_category_id' => $categoryId,
            'name' => 'River Overflow',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/incident-categories/{$categoryId}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'incident_types');
    }

    public function test_admin_can_hard_delete_unreferenced_incident_category(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Fire',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/incident-categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('incident_categories', [
            'id' => $categoryId,
        ]);
    }
}
