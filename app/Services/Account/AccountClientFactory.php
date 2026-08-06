<?php

namespace App\Services\Account;

use Illuminate\Http\Request;
use Pbb\AccountSdk\AccountClient;
use Pbb\AccountSdk\AccountConfig;

class AccountClientFactory
{
    public function __construct(private readonly AccountSsoSettings $settings) {}

    public function make(Request $request): AccountClient
    {
        return new AccountClient(
            new AccountConfig([
                'base_url' => $this->settings->baseUrl(),
                'client_id' => $this->settings->clientId(),
                'client_secret' => $this->settings->clientSecret(),
                'redirect_uri' => $this->settings->redirectUri(),
                'scopes' => $this->settings->scopes(),
                'timeout_seconds' => $this->settings->timeoutSeconds(),
                'ca_bundle' => $this->settings->caBundle(),
            ]),
            new LaravelAccountStateStore($request->session()),
        );
    }
}
