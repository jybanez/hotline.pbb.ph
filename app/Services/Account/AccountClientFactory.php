<?php

namespace App\Services\Account;

use Illuminate\Http\Request;
use Pbb\AccountSdk\AccountClient;
use Pbb\AccountSdk\AccountConfig;

class AccountClientFactory
{
    public function make(Request $request): AccountClient
    {
        return new AccountClient(
            new AccountConfig([
                'base_url' => config('account.base_url'),
                'client_id' => config('account.client_id'),
                'client_secret' => config('account.client_secret'),
                'redirect_uri' => config('account.redirect_uri'),
                'scopes' => config('account.scopes'),
                'timeout_seconds' => config('account.timeout_seconds'),
                'ca_bundle' => config('account.ca_bundle'),
            ]),
            new LaravelAccountStateStore($request->session()),
        );
    }
}
