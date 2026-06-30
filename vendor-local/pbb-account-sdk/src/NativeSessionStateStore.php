<?php

namespace Pbb\AccountSdk;

class NativeSessionStateStore implements AccountStateStoreInterface
{
    public function put(string $key, string $value): void
    {
        $this->ensureSession();

        $_SESSION[$key] = $value;
    }

    public function pull(string $key): ?string
    {
        $this->ensureSession();

        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
