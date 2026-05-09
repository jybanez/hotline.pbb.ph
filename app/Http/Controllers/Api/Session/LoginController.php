<?php

namespace App\Http\Controllers\Api\Session;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Session\LoginRequest;
use App\Support\Auth\RoleRedirector;
use App\Support\Bootstrap\BootstrapPayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(
        private readonly RoleRedirector $roleRedirector,
        private readonly BootstrapPayloadBuilder $bootstrapPayloadBuilder,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->validated())) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        if ($request->user()?->status !== UserStatus::Active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => ['This account is not currently allowed to sign in.'],
            ]);
        }

        $user = $request->user();

        if (($user?->role?->isCitizen() ?? false) || in_array($user?->role, [UserRole::Operator, UserRole::Command], true)) {
            config([
                'session.lifetime' => max(
                    (int) config('session.lifetime', 120),
                    (int) config('session.critical_lifetime', 43200),
                ),
            ]);

            Auth::guard('web')->login($user, true);
        }

        $request->session()->regenerate();
        $user?->forceFill(['last_login_at' => now()])->save();

        return response()->json(array_merge(
            $this->bootstrapPayloadBuilder->build($user, null),
            [
            'ok' => true,
            'user' => $user,
            'redirect_to' => $this->roleRedirector->homePathFor($user),
            'csrf_token' => $request->session()->token(),
            'session_lifetime_minutes' => (int) config('session.lifetime', 120),
            'session_touched_at' => now()->toIso8601String(),
            ],
        ));
    }
}
