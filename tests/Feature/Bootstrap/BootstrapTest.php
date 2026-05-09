<?php

namespace Tests\Feature\Bootstrap;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_bootstrap_returns_beta_defaults(): void
    {
        $response = $this->getJson('/api/bootstrap?surface=public');

        $response
            ->assertOk()
            ->assertJson([
                'authenticated' => false,
                'surface' => 'public',
                'alert_level' => 'Normal',
            ])
            ->assertJsonStructure(['csrf_token'])
            ->assertJsonPath('session_lifetime_minutes', 15)
            ->assertJsonPath('settings.call_hold_seconds', 3)
            ->assertJsonPath('settings.call_timeout_seconds', 20)
            ->assertJsonPath('settings.reconnect_timeout_seconds', 20);
    }

    public function test_operator_bootstrap_uses_critical_session_lifetime(): void
    {
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($operator)
            ->getJson('/api/bootstrap?surface=operator')
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('surface', 'operator')
            ->assertJsonPath('user.role', UserRole::Operator->value)
            ->assertJsonPath('session_lifetime_minutes', (int) config('session.critical_lifetime'));
    }
}
