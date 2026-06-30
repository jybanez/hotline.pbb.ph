<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'account-admin-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        config([
            'account.admin_api_enabled' => true,
            'account.admin_api_token' => self::TOKEN,
            'account.admin_api_client' => 'pbb-account',
        ]);
    }

    public function test_rejects_missing_or_invalid_service_auth(): void
    {
        $this->getJson('/api/account-admin/meta')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_account_client');

        $this->withHeaders(['X-PBB-Account-Client' => 'pbb-account'])
            ->getJson('/api/account-admin/meta')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_app_admin_token');

        $this->withHeaders([
            'X-PBB-Account-Client' => 'pbb-chat',
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->getJson('/api/account-admin/meta')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_account_client');
    }

    public function test_meta_returns_hotline_role_and_status_vocabulary(): void
    {
        $this->accountAdmin()
            ->getJson('/api/account-admin/meta')
            ->assertOk()
            ->assertJsonPath('data.app.id', 'pbb-hotline')
            ->assertJsonPath('data.roles.0.value', 'admin')
            ->assertJsonPath('data.roles.1.value', 'command')
            ->assertJsonPath('data.roles.2.value', 'operator')
            ->assertJsonPath('data.roles.3.value', 'citizen')
            ->assertJsonPath('data.statuses.0.value', 'active')
            ->assertJsonPath('data.statuses.1.value', 'suspended')
            ->assertJsonPath('data.statuses.2.value', 'disabled')
            ->assertJsonPath('data.statuses.3.value', 'pending')
            ->assertJsonPath('data.capabilities.provisionUser', true);
    }

    public function test_legacy_caller_role_is_exposed_as_citizen(): void
    {
        User::factory()->create([
            'pbb_user_id' => 'pbb-legacy-caller',
            'role' => UserRole::Caller,
            'status' => UserStatus::Active,
        ]);

        $this->accountAdmin()
            ->getJson('/api/account-admin/users/pbb-legacy-caller')
            ->assertOk()
            ->assertJsonPath('data.user.role', 'citizen');
    }

    public function test_lookup_returns_404_for_missing_linked_user(): void
    {
        $this->accountAdmin()
            ->getJson('/api/account-admin/users/pbb-missing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'linked_user_not_found');
    }

    public function test_put_idempotently_provisions_and_updates_linked_user(): void
    {
        $payload = [
            'name' => 'Maria Citizen',
            'email' => 'maria@example.test',
            'mobile' => '+639171234567',
            'defaultRole' => 'citizen',
        ];

        $this->accountAdmin()
            ->putJson('/api/account-admin/users/pbb-user-1', $payload)
            ->assertCreated()
            ->assertJsonPath('data.user.pbbUserId', 'pbb-user-1')
            ->assertJsonPath('data.user.role', 'citizen')
            ->assertJsonPath('data.user.status', 'active');

        $this->assertDatabaseCount('users', 1);

        $this->accountAdmin()
            ->putJson('/api/account-admin/users/pbb-user-1', [
                ...$payload,
                'name' => 'Maria Updated',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Maria Updated')
            ->assertJsonPath('data.user.role', 'citizen');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'pbb_user_id' => 'pbb-user-1',
            'name' => 'Maria Updated',
            'email' => 'maria@example.test',
        ]);
    }

    public function test_put_links_existing_unlinked_user_by_email_and_preserves_local_role_status(): void
    {
        $user = User::factory()->create([
            'pbb_user_id' => null,
            'email' => 'operator@example.test',
            'role' => UserRole::Operator,
            'status' => UserStatus::Suspended,
        ]);

        $this->accountAdmin()
            ->putJson('/api/account-admin/users/pbb-operator', [
                'name' => 'Operator Linked',
                'email' => 'operator@example.test',
                'defaultRole' => 'citizen',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.localUserId', (string) $user->id)
            ->assertJsonPath('data.user.role', 'operator')
            ->assertJsonPath('data.user.status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'pbb_user_id' => 'pbb-operator',
            'role' => UserRole::Operator->value,
            'status' => UserStatus::Suspended->value,
        ]);
    }

    public function test_role_patch_updates_local_role_and_records_audit(): void
    {
        User::factory()->create([
            'pbb_user_id' => 'pbb-command',
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);

        $this->accountAdmin()
            ->patchJson('/api/account-admin/users/pbb-command/role', [
                'role' => 'command',
                'reason' => 'Assigned by Account admin.',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'command');

        $this->assertDatabaseHas('users', [
            'pbb_user_id' => 'pbb-command',
            'role' => UserRole::Command->value,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'actor_role' => 'pbb-account',
            'action_type' => 'account_admin_role_updated',
            'message' => 'Assigned by Account admin.',
        ]);
    }

    public function test_status_patch_updates_local_status_and_records_audit(): void
    {
        User::factory()->create([
            'pbb_user_id' => 'pbb-status',
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);

        $this->accountAdmin()
            ->patchJson('/api/account-admin/users/pbb-status/status', [
                'status' => 'disabled',
                'reason' => 'Disabled by Account admin.',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.status', 'disabled');

        $this->assertDatabaseHas('users', [
            'pbb_user_id' => 'pbb-status',
            'status' => UserStatus::Disabled->value,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'actor_role' => 'pbb-account',
            'action_type' => 'account_admin_status_updated',
            'message' => 'Disabled by Account admin.',
        ]);
    }

    public function test_invalid_role_and_status_return_meaningful_json_errors(): void
    {
        User::factory()->create([
            'pbb_user_id' => 'pbb-invalid',
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);

        $this->accountAdmin()
            ->patchJson('/api/account-admin/users/pbb-invalid/role', ['role' => 'staff'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_role')
            ->assertJsonPath('error.details.allowed.0', 'admin');

        $this->accountAdmin()
            ->patchJson('/api/account-admin/users/pbb-invalid/status', ['status' => 'blocked'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_status')
            ->assertJsonPath('error.details.allowed.0', 'active');
    }

    public function test_local_login_still_uses_local_role_and_status_ownership(): void
    {
        $user = User::factory()->create([
            'email' => 'command@example.test',
            'password' => Hash::make('secret-password'),
            'pbb_user_id' => 'pbb-local-command',
            'role' => UserRole::Command,
            'status' => UserStatus::Active,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', UserRole::Command->value)
            ->assertJsonPath('redirect_to', '/command');
    }

    private function accountAdmin(): self
    {
        return $this->withHeaders([
            'X-PBB-Account-Client' => 'pbb-account',
            'Authorization' => 'Bearer '.self::TOKEN,
        ]);
    }
}
