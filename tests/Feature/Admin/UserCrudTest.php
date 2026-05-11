<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_admin_can_create_and_update_user_with_default_active_status(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $create = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Dispatch User',
            'mobile' => '09175555555',
            'email' => 'dispatch@example.test',
            'role' => 'operator',
            'password' => 'strong-pass',
        ]);

        $create
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.status', 'active');

        $userId = $create->json('user.id');

        $this->actingAs($admin)->postJson("/api/admin/users/{$userId}", [
            'name' => 'Dispatch Updated',
            'mobile' => '09176666666',
            'email' => 'dispatch.updated@example.test',
            'role' => 'operator',
            'status' => 'suspended',
        ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Dispatch Updated')
            ->assertJsonPath('user.status', 'suspended');
    }

    public function test_admin_can_list_and_show_users(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'name' => 'Alpha Admin',
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'name' => 'Bravo Operator',
            'email' => 'bravo@example.test',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/users?search=Bravo')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $operator->id);

        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$operator->id}")
            ->assertOk()
            ->assertJsonPath('id', $operator->id)
            ->assertJsonPath('email', 'bravo@example.test');
    }

    public function test_admin_delete_is_blocked_when_user_is_referenced(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        DB::table('incidents')->insert([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => 'Active',
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$caller->id}")
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('references.0.table', 'incidents')
            ->assertJsonPath('references.0.column', 'citizen_id')
            ->assertJsonPath('references.0.label', 'Incidents as citizen');
    }

    public function test_admin_can_hard_delete_unreferenced_user(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $user = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    }
}
