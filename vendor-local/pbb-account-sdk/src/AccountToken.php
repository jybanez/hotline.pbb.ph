<?php

namespace Pbb\AccountSdk;

class AccountToken
{
    public ?string $accessToken;
    public string $tokenType;
    public int $expiresIn;
    public AccountIdentity $identity;
    public array $raw;

    public function __construct(array $payload)
    {
        $this->accessToken = isset($payload['access_token']) ? (string) $payload['access_token'] : null;
        $this->tokenType = (string) ($payload['token_type'] ?? 'Bearer');
        $this->expiresIn = (int) ($payload['expires_in'] ?? 0);
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
            'identity' => $this->identity->toArray(),
            'raw' => $this->raw,
        ];
    }
}
