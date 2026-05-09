<?php

namespace Tests\Feature\Routing;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurfaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_public_home_when_opening_legacy_caller_surface(): void
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
            ->assertOk()
            ->assertSee('/citizen.webmanifest', false);

        $this->actingAs($citizen)
            ->get('/caller')
            ->assertOk();
    }

    public function test_citizen_pwa_assets_exist_and_keep_legacy_caller_compatibility(): void
    {
        self::assertFileExists(public_path('citizen.webmanifest'));
        self::assertFileExists(public_path('citizen-sw.js'));
        self::assertFileExists(public_path('caller.webmanifest'));
        self::assertFileExists(public_path('caller-sw.js'));

        $citizenManifest = file_get_contents(public_path('citizen.webmanifest'));
        $citizenServiceWorker = file_get_contents(public_path('citizen-sw.js'));
        $legacyCallerServiceWorker = file_get_contents(public_path('caller-sw.js'));

        self::assertStringContainsString('/citizen?source=pwa', $citizenManifest);
        self::assertStringContainsString('/citizen/offline', $citizenServiceWorker);
        self::assertStringContainsString('/caller/offline', $citizenServiceWorker);
        self::assertStringContainsString('/citizen.webmanifest', $legacyCallerServiceWorker);
        self::assertStringContainsString('/caller/offline', $legacyCallerServiceWorker);
    }

    public function test_wrong_role_is_redirected_to_unauthorized_screen(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($citizen)
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
