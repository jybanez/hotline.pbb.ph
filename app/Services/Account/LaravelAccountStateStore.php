<?php

namespace App\Services\Account;

use Illuminate\Session\Store;
use Pbb\AccountSdk\AccountStateStoreInterface;

class LaravelAccountStateStore implements AccountStateStoreInterface
{
    public function __construct(private Store $session) {}

    public function put(string $key, string $value): void
    {
        $this->session->put($this->sessionKey($key), $value);
        $this->session->save();
    }

    public function pull(string $key): ?string
    {
        $value = $this->session->pull($this->sessionKey($key));
        $this->session->save();

        return is_string($value) ? $value : null;
    }

    private function sessionKey(string $key): string
    {
        return 'pbb_account.'.$key;
    }
}
