<?php

namespace Pbb\AccountSdk;

class AccountIdentity
{
    public string $pbbUserId;
    public string $name;
    public ?string $email;
    public ?string $mobile;
    public ?string $status;
    public bool $emailVerified;
    public bool $mobileVerified;
    public array $raw;

    public function __construct(array $payload)
    {
        $payload = isset($payload['user']) && is_array($payload['user']) ? $payload['user'] : $payload;

        $pbbUserId = trim((string) ($payload['pbb_user_id'] ?? ''));
        if ($pbbUserId === '') {
            throw new AccountProtocolException('Account identity response is missing pbb_user_id.');
        }

        $this->pbbUserId = $pbbUserId;
        $this->name = (string) ($payload['name'] ?? '');
        $this->email = $this->nullableString($payload['email'] ?? null);
        $this->mobile = $this->nullableString($payload['mobile'] ?? null);
        $this->status = $this->nullableString($payload['status'] ?? null);
        $this->emailVerified = (bool) ($payload['email_verified'] ?? $payload['email_verified_at'] ?? false);
        $this->mobileVerified = (bool) ($payload['mobile_verified'] ?? $payload['mobile_verified_at'] ?? false);
        $this->raw = $payload;
    }

    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    public function toArray(): array
    {
        return [
            'pbb_user_id' => $this->pbbUserId,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'status' => $this->status,
            'email_verified' => $this->emailVerified,
            'mobile_verified' => $this->mobileVerified,
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
