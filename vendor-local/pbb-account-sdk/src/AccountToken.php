<?php

namespace Pbb\AccountSdk;

class AccountToken
{
    public ?string $accessToken;
    public string $tokenType;
    public int $expiresIn;
    public ?string $accountSessionId;
    public ?string $nonce;
    public AccountIdentity $identity;
    public array $raw;

    public function __construct(array $payload)
    {
        $this->accessToken = isset($payload['access_token']) ? (string) $payload['access_token'] : null;
        $this->tokenType = (string) ($payload['token_type'] ?? 'Bearer');
        $this->expiresIn = (int) ($payload['expires_in'] ?? 0);
        $this->accountSessionId = $this->nullableString($payload['account_session_id'] ?? null);
        $this->nonce = $this->nullableString($payload['nonce'] ?? null);
        $this->identity = AccountIdentity::fromArray($payload);
        $this->raw = $payload;
    }

    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'account_session_id' => $this->accountSessionId,
            'nonce' => $this->nonce,
            'identity' => $this->identity->toArray(),
            'raw' => $this->raw,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
