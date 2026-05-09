<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_login_uses_critical_session_and_can_log_out(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.test',
            'password' => Hash::make('secret-password'),
            'role' => UserRole::Operator,
            'status' => UserStatus::Active,
            'remember_token' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('redirect_to', '/operator')
            ->assertJsonPath('session_lifetime_minutes', (int) config('session.critical_lifetime'))
            ->assertJsonStructure(['csrf_token']);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertNotNull($user->fresh()->remember_token);

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['csrf_token']);
    }

    public function test_command_login_uses_critical_session(): void
    {
        $user = User::factory()->create([
            'email' => 'command@example.test',
            'password' => Hash::make('secret-password'),
            'role' => UserRole::Command,
            'status' => UserStatus::Active,
            'remember_token' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.role', UserRole::Command->value)
            ->assertJsonPath('redirect_to', '/command')
            ->assertJsonPath('session_lifetime_minutes', (int) config('session.critical_lifetime'));

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_caller_login_persists_a_remember_token(): void
    {
        $user = User::factory()->create([
            'email' => 'caller@example.test',
            'password' => Hash::make('secret-password'),
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
            'remember_token' => null,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.role', UserRole::Citizen->value)
            ->assertJsonPath('redirect_to', '/citizen')
            ->assertJsonPath('session_lifetime_minutes', (int) config('session.critical_lifetime'));

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_non_active_user_is_rejected_after_credentials_match(): void
    {
        $user = User::factory()->create([
            'email' => 'blocked@example.test',
            'password' => Hash::make('secret-password'),
            'status' => UserStatus::Suspended,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_authenticated_user_can_ping_session_keepalive(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Operator,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user)
            ->getJson('/api/session/ping')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'csrf_token',
                'touched_at',
                'data' => ['csrf_token', 'touched_at'],
            ]);
    }

    public function test_guest_can_refresh_csrf_token(): void
    {
        $this->getJson('/api/csrf-token')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['csrf_token']);
    }

    public function test_authenticated_user_can_reauth_and_receive_shell_fields(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'password' => Hash::make('secret-password'),
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user)
            ->postJson('/api/reauth', [
                'email' => $user->email,
                'password' => 'secret-password',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('redirect_to', '/admin')
            ->assertJsonStructure([
                'csrf_token',
                'session_touched_at',
                'session_lifetime_minutes',
                'alert_level',
                'alert_level_description',
                'settings',
            ]);
    }
}
