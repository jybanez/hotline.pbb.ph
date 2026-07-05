<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use App\Services\Account\AccountClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Pbb\AccountSdk\AccountClient;
use Pbb\AccountSdk\AccountIdentity;
use Pbb\AccountSdk\AccountProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSsoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'account.enabled' => true,
            'account.base_url' => 'https://account.pbb.ph',
            'account.client_id' => 'pbb-hotline',
            'account.client_secret' => 'pbb-hotline-dev-secret',
            'account.redirect_uri' => 'https://hotline.pbb.ph/auth/account/callback',
            'account.post_logout_redirect_uri' => 'https://hotline.pbb.ph',
            'account.scopes' => ['openid', 'profile'],
        ]);
    }

    public function test_redirect_route_sends_browser_to_account_authorize(): void
    {
        $client = Mockery::mock(AccountClient::class);
        $client->shouldReceive('authorizationUrl')
            ->once()
            ->andReturn('https://account.pbb.ph/oauth/authorize?client_id=pbb-hotline');

        $factory = Mockery::mock(AccountClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);
        $this->app->instance(AccountClientFactory::class, $factory);

        $this->get('/auth/account/redirect')
            ->assertRedirect('https://account.pbb.ph/oauth/authorize?client_id=pbb-hotline');
    }

    public function test_redirect_route_stores_safe_return_path(): void
    {
        $client = Mockery::mock(AccountClient::class);
        $client->shouldReceive('authorizationUrl')
            ->once()
            ->andReturn('https://account.pbb.ph/oauth/authorize?client_id=pbb-hotline');

        $factory = Mockery::mock(AccountClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);
        $this->app->instance(AccountClientFactory::class, $factory);

        $this->get('/auth/account/redirect?return_to=/command')
            ->assertRedirect('https://account.pbb.ph/oauth/authorize?client_id=pbb-hotline')
            ->assertSessionHas('pbb_account.return_to', '/command');
    }

    public function test_callback_success_provisions_citizen_user_and_logs_in(): void
    {
        $this->mockAccountCallback([
            'pbb_user_id' => 'pbb-user-123',
            'name' => 'Maria Citizen',
            'email' => 'maria@example.test',
            'mobile' => '+639171234567',
        ]);

        $this->get('/auth/account/callback?code=valid-code&state=valid-state')
            ->assertRedirect('/citizen')
            ->assertSessionHas('account_login_success', true);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'pbb_user_id' => 'pbb-user-123',
            'email' => 'maria@example.test',
            'name' => 'Maria Citizen',
            'mobile' => '+639171234567',
            'role' => UserRole::Citizen->value,
            'status' => UserStatus::Active->value,
        ]);
    }

    public function test_callback_success_matches_existing_user_by_pbb_user_id(): void
    {
        $existing = User::factory()->create([
            'pbb_user_id' => 'pbb-user-456',
            'name' => 'Old Name',
            'email' => 'old@example.test',
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);

        $this->mockAccountCallback([
            'pbb_user_id' => 'pbb-user-456',
            'name' => 'Updated Citizen',
            'email' => 'updated@example.test',
        ]);

        $this->get('/auth/account/callback?code=valid-code&state=valid-state')
            ->assertRedirect('/citizen');

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'pbb_user_id' => 'pbb-user-456',
            'name' => 'Updated Citizen',
            'email' => 'updated@example.test',
        ]);
    }

    #[DataProvider('accountLinkedStaffRoleProvider')]
    public function test_callback_success_allows_account_linked_staff_users(UserRole $role, string $expectedRedirect): void
    {
        $existing = User::factory()->create([
            'pbb_user_id' => 'pbb-staff-123',
            'name' => 'Old Staff Name',
            'email' => 'old-staff@example.test',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);

        $this->mockAccountCallback([
            'pbb_user_id' => 'pbb-staff-123',
            'name' => 'Updated Staff',
            'email' => 'updated-staff@example.test',
        ]);

        $this->get('/auth/account/callback?code=valid-code&state=valid-state')
            ->assertRedirect($expectedRedirect)
            ->assertSessionHas('account_login_success', true);

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'pbb_user_id' => 'pbb-staff-123',
            'name' => 'Updated Staff',
            'email' => 'updated-staff@example.test',
            'role' => $role->value,
            'status' => UserStatus::Active->value,
        ]);
    }

    public function test_callback_does_not_email_link_staff_without_account_identity_link(): void
    {
        User::factory()->create([
            'pbb_user_id' => null,
            'email' => 'staff@example.test',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);

        $this->mockAccountCallback([
            'pbb_user_id' => 'pbb-staff-unlinked',
            'name' => 'Staff User',
            'email' => 'staff@example.test',
        ]);

        $this->get('/auth/account/callback?code=valid-code&state=valid-state')
            ->assertRedirect('/')
            ->assertSessionHas('account_login_error', 'This email belongs to a local Hotline staff account that must be linked by Account first.');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'pbb_user_id' => 'pbb-staff-unlinked',
        ]);
    }

    public function test_callback_failure_redirects_home_with_error(): void
    {
        $client = Mockery::mock(AccountClient::class);
        $client->shouldReceive('handleCallback')
            ->once()
            ->andThrow(new AccountProtocolException('Account callback state is invalid or expired.'));

        $factory = Mockery::mock(AccountClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);
        $this->app->instance(AccountClientFactory::class, $factory);

        $this->get('/auth/account/callback?code=bad-code&state=bad-state')
            ->assertRedirect('/')
            ->assertSessionHas('account_login_error', 'Account callback state is invalid or expired.');

        $this->assertGuest();
    }

    public function test_bootstrap_exposes_account_callback_error_once(): void
    {
        session()->flash('account_login_error', 'Account callback state is invalid or expired.');

        $this->getJson('/api/bootstrap?surface=public')
            ->assertOk()
            ->assertJsonPath('auth.account_sso.error', 'Account callback state is invalid or expired.');

        $this->getJson('/api/bootstrap?surface=public')
            ->assertOk()
            ->assertJsonPath('auth.account_sso.error', null);
    }

    public function test_logout_clears_local_session_then_redirects_to_account_logout(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user)
            ->get('/auth/logout')
            ->assertRedirect('https://account.pbb.ph/oauth/logout?client_id=pbb-hotline&post_logout_redirect_uri=https%3A%2F%2Fhotline.pbb.ph');

        $this->assertFalse(Auth::check());
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function mockAccountCallback(array $identity): void
    {
        $client = Mockery::mock(AccountClient::class);
        $client->shouldReceive('handleCallback')
            ->once()
            ->andReturn(AccountIdentity::fromArray($identity));

        $factory = Mockery::mock(AccountClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);
        $this->app->instance(AccountClientFactory::class, $factory);
    }

    /**
     * @return array<string, array{UserRole, string}>
     */
    public static function accountLinkedStaffRoleProvider(): array
    {
        return [
            'admin' => [UserRole::Admin, '/admin'],
            'command' => [UserRole::Command, '/command'],
            'operator' => [UserRole::Operator, '/operator'],
        ];
    }
}
