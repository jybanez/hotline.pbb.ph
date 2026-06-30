<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use App\Services\Account\AccountClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Pbb\AccountSdk\AccountException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AccountSsoController extends Controller
{
    public function redirect(Request $request, AccountClientFactory $accounts): RedirectResponse
    {
        abort_unless(config('account.enabled'), 404);

        $request->session()->put('pbb_account.return_to', '/citizen');

        return redirect()->away($accounts->make($request)->authorizationUrl());
    }

    public function callback(Request $request, AccountClientFactory $accounts): RedirectResponse
    {
        abort_unless(config('account.enabled'), 404);

        try {
            $identity = $accounts->make($request)->handleCallback($request->query())->toArray();
            $user = $this->resolveCitizenUser($identity);
            $this->assertLocalAccessAllowed($user);

            Auth::guard('web')->login($user, true);
            $request->session()->regenerate();
            $user->forceFill(['last_login_at' => now()])->save();

            return redirect($request->session()->pull('pbb_account.return_to', '/citizen'))
                ->with('account_login_success', true);
        } catch (AccountException $exception) {
            return redirect('/')->with('account_login_error', $exception->getMessage());
        } catch (HttpExceptionInterface $exception) {
            return redirect('/')->with('account_login_error', $exception->getMessage() ?: 'Account sign in was rejected.');
        } catch (\Throwable $exception) {
            report($exception);

            return redirect('/')->with('account_login_error', 'Unable to complete Account sign in.');
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! config('account.enabled')) {
            return redirect('/');
        }

        return redirect()->away($this->accountLogoutUrl());
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function resolveCitizenUser(array $identity): User
    {
        $pbbUserId = trim((string) ($identity['pbb_user_id'] ?? ''));
        $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
        $mobile = trim((string) ($identity['mobile'] ?? ''));
        $name = trim((string) ($identity['name'] ?? '')) ?: ($email ?: 'PBB Citizen');

        abort_if($pbbUserId === '', 422, 'Account identity is missing pbb_user_id.');

        $user = User::query()->where('pbb_user_id', $pbbUserId)->first();
        abort_if($user && ! ($user->role?->isCitizen() ?? false), 409, 'This Account identity is linked to a local Hotline staff account.');

        if (! $user && $email !== '') {
            $emailUser = User::query()->where('email', $email)->first();

            if ($emailUser) {
                abort_if(! ($emailUser->role?->isCitizen() ?? false), 409, 'This email belongs to a local Hotline staff account.');
                abort_if($emailUser->pbb_user_id && $emailUser->pbb_user_id !== $pbbUserId, 409, 'This email is already linked to another Account identity.');

                $user = $emailUser;
            }
        }

        if (! $user) {
            $user = new User([
                'role' => UserRole::Citizen,
                'status' => UserStatus::Active,
                'password' => Hash::make(Str::random(64)),
            ]);
        }

        $user->forceFill([
            'pbb_user_id' => $pbbUserId,
            'name' => $name,
            'email' => $email !== '' ? $email : $this->syntheticEmail($pbbUserId),
            'mobile' => $mobile !== '' ? $mobile : $user->mobile,
            'role' => $user->role ?? UserRole::Citizen,
            'status' => $user->status ?? UserStatus::Active,
        ])->save();

        return $user;
    }

    private function assertLocalAccessAllowed(User $user): void
    {
        abort_if($user->status !== UserStatus::Active, 403, 'This Hotline account is not currently allowed to sign in.');
        abort_if(! ($user->role?->isCitizen() ?? false), 403, 'Account SSO is only enabled for Hotline citizen access.');
    }

    private function syntheticEmail(string $pbbUserId): string
    {
        $slug = Str::slug($pbbUserId, '-') ?: sha1($pbbUserId);

        return 'account-'.Str::limit($slug, 80, '').'@account.pbb.local';
    }

    private function accountLogoutUrl(): string
    {
        $baseUrl = rtrim((string) config('account.base_url'), '/');

        return $baseUrl.'/oauth/logout?'.http_build_query([
            'client_id' => config('account.client_id'),
            'post_logout_redirect_uri' => config('account.post_logout_redirect_uri') ?: url('/'),
        ]);
    }
}
