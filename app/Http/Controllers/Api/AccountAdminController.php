<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountAdminController extends Controller
{
    private const ROLES = [
        UserRole::Admin->value,
        UserRole::Command->value,
        UserRole::Operator->value,
        UserRole::Citizen->value,
    ];

    public function meta(): JsonResponse
    {
        return $this->ok([
            'app' => [
                'id' => 'pbb-hotline',
                'name' => 'PBB Hotline',
            ],
            'roles' => [
                ['value' => UserRole::Admin->value, 'label' => 'Admin'],
                ['value' => UserRole::Command->value, 'label' => 'Command'],
                ['value' => UserRole::Operator->value, 'label' => 'Operator'],
                ['value' => UserRole::Citizen->value, 'label' => 'Citizen'],
            ],
            'statuses' => array_map(
                static fn (UserStatus $status): array => [
                    'value' => $status->value,
                    'label' => Str::headline($status->value),
                ],
                UserStatus::cases(),
            ),
            'capabilities' => [
                'provisionUser' => true,
                'updateRole' => true,
                'updateStatus' => true,
                'removeUser' => true,
                'blockLogin' => false,
                'suspendLogin' => true,
            ],
        ]);
    }

    public function show(string $pbb_user_id): JsonResponse
    {
        $user = $this->findLinkedUser($pbb_user_id);

        if (! $user) {
            return $this->accountFail('linked_user_not_found', 'Linked user not found.', 404);
        }

        return $this->ok([
            'user' => $this->accountUserPayload($user),
        ]);
    }

    public function provision(Request $request, string $pbb_user_id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:191'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'defaultRole' => ['nullable', 'string', Rule::in(self::ROLES)],
        ]);

        $role = $data['defaultRole'] ?? UserRole::Citizen->value;
        $email = $this->normalizedEmail($data['email'] ?? null);
        $mobile = $this->text($data['mobile'] ?? null);

        return DB::transaction(function () use ($pbb_user_id, $data, $role, $email, $mobile): JsonResponse {
            $linked = $this->findLinkedUser($pbb_user_id);

            if ($linked) {
                $linked->forceFill($this->safeIdentityFields($data['name'], $email, $mobile, $linked))->save();
                $this->recordAccountAdminAction($linked, 'account_admin_user_synced', null);

                return $this->ok([
                    'user' => $this->accountUserPayload($linked),
                ]);
            }

            $emailUser = $email !== null
                ? User::query()->where('email', $email)->lockForUpdate()->first()
                : null;

            if ($emailUser && $emailUser->pbb_user_id && $emailUser->pbb_user_id !== $pbb_user_id) {
                return $this->accountFail('identity_conflict', 'A user with this email is linked to a different Account identity.', 409, [
                    'email' => $email,
                ]);
            }

            if ($emailUser) {
                $emailUser->forceFill([
                    ...$this->safeIdentityFields($data['name'], $email, $mobile, $emailUser),
                    'pbb_user_id' => $pbb_user_id,
                ])->save();

                $this->recordAccountAdminAction($emailUser, 'account_admin_user_linked', null);

                return $this->ok([
                    'user' => $this->accountUserPayload($emailUser),
                ]);
            }

            $user = User::query()->create([
                'pbb_user_id' => $pbb_user_id,
                'name' => trim((string) $data['name']),
                'email' => $email ?? $this->syntheticEmail($pbb_user_id),
                'mobile' => $mobile,
                'role' => $role,
                'status' => UserStatus::Active,
                'password' => Hash::make(Str::random(64)),
            ]);

            $this->recordAccountAdminAction($user, 'account_admin_user_provisioned', null);

            return $this->ok([
                'user' => $this->accountUserPayload($user),
            ], 201);
        });
    }

    public function updateRole(Request $request, string $pbb_user_id): JsonResponse
    {
        $validator = validator($request->all(), [
            'role' => ['required', 'string', Rule::in(self::ROLES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->accountFail('invalid_role', 'The requested role is not allowed.', 422, [
                'allowed' => self::ROLES,
            ]);
        }

        $data = $validator->validated();
        $user = $this->findLinkedUser($pbb_user_id);

        if (! $user) {
            return $this->accountFail('linked_user_not_found', 'Linked user not found.', 404);
        }

        $user->forceFill(['role' => $data['role']])->save();

        $this->recordAccountAdminAction($user, 'account_admin_role_updated', $data['reason'] ?? null);

        return $this->ok([
            'user' => $this->accountUserPayload($user),
        ]);
    }

    public function updateStatus(Request $request, string $pbb_user_id): JsonResponse
    {
        $allowedStatuses = array_map(static fn (UserStatus $status): string => $status->value, UserStatus::cases());
        $validator = validator($request->all(), [
            'status' => ['required', 'string', Rule::in($allowedStatuses)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->accountFail('invalid_status', 'The requested status is not allowed.', 422, [
                'allowed' => $allowedStatuses,
            ]);
        }

        $data = $validator->validated();
        $user = $this->findLinkedUser($pbb_user_id);

        if (! $user) {
            return $this->accountFail('linked_user_not_found', 'Linked user not found.', 404);
        }

        $user->forceFill(['status' => $data['status']])->save();

        $this->recordAccountAdminAction($user, 'account_admin_status_updated', $data['reason'] ?? null);

        return $this->ok([
            'user' => $this->accountUserPayload($user),
        ]);
    }

    public function removeAccess(Request $request, string $pbb_user_id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $this->findLinkedUser($pbb_user_id);

        if (! $user) {
            return $this->ok([
                'removed' => false,
                'pbbUserId' => $pbb_user_id,
                'status' => 'not_linked',
            ]);
        }

        $user->forceFill([
            'pbb_user_id' => null,
            'status' => UserStatus::Disabled,
        ])->save();

        $this->recordAccountAdminAction($user, 'account_admin_access_removed', $data['reason'] ?? null);

        return $this->ok([
            'removed' => true,
            'user' => $this->accountUserPayload($user),
        ]);
    }

    private function findLinkedUser(string $pbbUserId): ?User
    {
        return User::query()
            ->where('pbb_user_id', $pbbUserId)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountUserPayload(User $user): array
    {
        return [
            'pbbUserId' => $user->pbb_user_id,
            'localUserId' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'role' => $this->roleFor($user),
            'status' => $user->status?->value,
            'blockedAt' => null,
            'suspendedUntil' => null,
            'updatedAt' => $user->updated_at?->toIso8601String(),
        ];
    }

    private function roleFor(User $user): ?string
    {
        if ($user->role?->isCitizen()) {
            return UserRole::Citizen->value;
        }

        return $user->role?->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeIdentityFields(string $name, ?string $email, ?string $mobile, User $user): array
    {
        return array_filter([
            'name' => trim($name),
            'email' => $email,
            'mobile' => $mobile ?? $user->mobile,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
        ], $status, $this->noStoreHeaders());
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function accountFail(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ], $status, $this->noStoreHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function noStoreHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ];
    }

    private function recordAccountAdminAction(User $target, string $actionType, ?string $reason): void
    {
        DB::table('activity_logs')->insert([
            'incident_id' => null,
            'actor_id' => null,
            'actor_role' => 'pbb-account',
            'action_type' => $actionType,
            'message' => $reason ?: 'PBB Account app-admin API updated a local Hotline user.',
            'created_at' => now(),
        ]);
    }

    private function normalizedEmail(mixed $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function syntheticEmail(string $pbbUserId): string
    {
        $slug = Str::slug($pbbUserId, '-') ?: sha1($pbbUserId);

        return 'account-'.Str::limit($slug, 80, '').'@account.pbb.local';
    }
}
