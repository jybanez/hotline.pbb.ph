<?php

namespace Pbb\AccountSdk;

interface AccountStateStoreInterface
{
    public function put(string $key, string $value): void;

    public function pull(string $key): ?string;
}
