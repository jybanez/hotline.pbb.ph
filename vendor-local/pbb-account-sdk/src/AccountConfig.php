<?php

namespace Pbb\AccountSdk;

class AccountConfig
{
    public string $baseUrl;
    public string $clientId;
    public ?string $clientSecret;
    public string $redirectUri;
    /** @var list<string> */
    public array $scopes;
    public int $timeoutSeconds;
    public ?string $caBundle;
    public string $stateKey;

    public function __construct(array $config)
    {
        $this->baseUrl = $this->required($config, 'base_url');
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->clientId = $this->required($config, 'client_id');
        $this->clientSecret = isset($config['client_secret']) ? (string) $config['client_secret'] : null;
        $this->redirectUri = $this->required($config, 'redirect_uri');
        $this->scopes = $this->normalizeScopes($config['scopes'] ?? ['openid', 'profile']);
        $this->timeoutSeconds = max(1, (int) ($config['timeout_seconds'] ?? 10));
        $this->caBundle = isset($config['ca_bundle']) ? (string) $config['ca_bundle'] : null;
        $this->stateKey = (string) ($config['state_key'] ?? '_pbb_account_oauth_state');
    }

    public function scopesString(): string
    {
        return implode(' ', $this->scopes);
    }

    private function required(array $config, string $key): string
    {
        $value = trim((string) ($config[$key] ?? ''));

        if ($value === '') {
            throw new AccountProtocolException("Missing required Account SDK config value: {$key}");
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(array|string $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = preg_split('/\s+/', trim($scopes)) ?: [];
        }

        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '') {
                $normalized[] = $scope;
            }
        }

        return array_values(array_unique($normalized));
    }
}
