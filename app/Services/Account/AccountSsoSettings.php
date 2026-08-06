<?php

namespace App\Services\Account;

use App\Support\Settings\SettingsService;

class AccountSsoSettings
{
    public function __construct(private readonly SettingsService $settings) {}

    public function enabled(): bool
    {
        return filter_var($this->value('account_sso_enabled', config('account.enabled')), FILTER_VALIDATE_BOOLEAN);
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->value('account_sso_base_url', config('account.base_url')), '/');
    }

    public function clientId(): string
    {
        return trim((string) $this->value('account_sso_client_id', config('account.client_id')));
    }

    public function clientSecret(): string
    {
        return trim((string) $this->value('account_sso_client_secret', config('account.client_secret')));
    }

    public function redirectUri(): string
    {
        return trim((string) $this->value('account_sso_redirect_uri', config('account.redirect_uri')));
    }

    public function postLogoutRedirectUri(): string
    {
        return trim((string) $this->value('account_sso_post_logout_redirect_uri', config('account.post_logout_redirect_uri')));
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        $value = $this->value('account_sso_scopes', config('account.scopes', []));

        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn ($scope) => trim((string) $scope), $value)));
        }

        return array_values(array_filter(preg_split('/[\s,]+/', trim((string) $value)) ?: []));
    }

    public function timeoutSeconds(): int
    {
        $timeout = (int) $this->value('account_sso_timeout_seconds', config('account.timeout_seconds', 10));

        return $timeout > 0 ? $timeout : 10;
    }

    public function caBundle(): ?string
    {
        $bundle = trim((string) $this->value('account_sso_ca_bundle', config('account.ca_bundle')));

        return $bundle !== '' ? $bundle : null;
    }

    public function ready(): bool
    {
        return $this->enabled()
            && $this->baseUrl() !== ''
            && $this->clientId() !== ''
            && $this->clientSecret() !== ''
            && $this->redirectUri() !== '';
    }

    public function logoutUrl(): string
    {
        return $this->baseUrl().'/oauth/logout?'.http_build_query([
            'client_id' => $this->clientId(),
            'post_logout_redirect_uri' => $this->postLogoutRedirectUri() ?: url('/'),
        ]);
    }

    private function value(string $key, mixed $fallback): mixed
    {
        return $this->settings->get($key, $fallback);
    }
}
