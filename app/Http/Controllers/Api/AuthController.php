<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends BaseApiController
{
    public function csrfToken(Request $request)
    {
        $request->session()->regenerateToken();

        return $this->ok([
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, false)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $request->session()->regenerate();

        return $this->ok([
            'account' => $this->toAccount($request->user()),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->ok([
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function user(Request $request)
    {
        return $this->ok([
            'account' => $this->toAccount($request->user()),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    public function ping(Request $request)
    {
        $request->session()->regenerateToken();

        return $this->ok([
            'account' => $this->toAccount($request->user()),
            'csrf_token' => $request->session()->token(),
        ]);
    }

    /**
     * @param  \App\Models\User|null  $user
     */
    private function toAccount($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
}
