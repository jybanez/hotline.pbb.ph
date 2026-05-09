<?php

namespace Tests\Feature\Routing;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurfaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_public_home_when_opening_caller_surface(): void
    {
        $this->get('/caller')
            ->assertRedirect(route('public.home'));
    }

    public function test_citizen_user_can_open_citizen_and_legacy_caller_surfaces(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($citizen)
            ->get('/citizen')
            ->assertOk();

        $this->actingAs($citizen)
            ->get('/caller')
            ->assertOk();
    }

    public function test_wrong_role_is_redirected_to_unauthorized_screen(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($caller)
            ->get('/operator')
            ->assertRedirect(route('unauthorized'));
    }

    public function test_authenticated_public_home_redirects_to_role_surface(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($citizen)
            ->get('/')
            ->assertRedirect('/citizen');
    }

    public function test_command_user_can_open_command_surface(): void
    {
        $command = User::factory()->create([
            'role' => UserRole::Command,
        ]);

        $this->actingAs($command)
            ->get('/command')
            ->assertOk();
    }
}
