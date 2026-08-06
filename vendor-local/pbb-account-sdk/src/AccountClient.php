<?php

namespace Pbb\AccountSdk;

class AccountClient
{
    public function __construct(
        private AccountConfig $config,
        private ?AccountStateStoreInterface $stateStore = null,
        private ?AccountHttpTransportInterface $transport = null
    ) {
        $this->stateStore ??= new NativeSessionStateStore();
        $this->transport ??= new CurlAccountHttpTransport();
    }

    public function authorizationUrl(array $extraParams = []): string
    {
        $state = bin2hex(random_bytes(16));
        $nonce = (string) ($extraParams['nonce'] ?? bin2hex(random_bytes(32)));
        $this->stateStore->put($this->config->stateKey, $state);
        $this->stateStore->put($this->config->nonceKey, $nonce);

        $params = array_merge($extraParams, [
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'response_type' => 'code',
            'scope' => $this->config->scopesString(),
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return $this->config->baseUrl.'/oauth/authorize?'.http_build_query($params);
    }

    public function authorizeUrl(array $extraParams = []): string
    {
        return $this->authorizationUrl($extraParams);
    }

    public function handleCallback(array $query): AccountIdentity
    {
        return $this->handleCallbackToken($query)->identity;
    }

    public function handleCallbackToken(array $query): AccountToken
    {
        if (isset($query['error'])) {
            $message = (string) ($query['error_description'] ?? $query['error']);
            throw new AccountProtocolException($message);
        }

        $code = trim((string) ($query['code'] ?? ''));
        if ($code === '') {
            throw new AccountProtocolException('Account callback is missing authorization code.');
        }

        $incomingState = (string) ($query['state'] ?? '');
        $expectedState = $this->stateStore->pull($this->config->stateKey);

        if ($incomingState === '' || $expectedState === null || ! hash_equals($expectedState, $incomingState)) {
            throw new AccountProtocolException('Account callback state is invalid or expired.');
        }

        $expectedNonce = $this->stateStore->pull($this->config->nonceKey);
        $token = $this->exchangeCode($code, $expectedNonce);

        if ($expectedNonce !== null && ! hash_equals($expectedNonce, (string) $token->nonce)) {
            throw new AccountProtocolException('Account callback nonce is invalid.');
        }

        return $token;
    }

    public function exchangeCode(string $code, ?string $nonce = null): AccountToken
    {
        $clientSecret = trim((string) $this->config->clientSecret);
        if ($clientSecret === '') {
            throw new AccountProtocolException('Account client secret is required for authorization code exchange.');
        }

        $requestPayload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->config->clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $this->config->redirectUri,
        ];

        if ($nonce !== null) {
            $requestPayload['nonce'] = $nonce;
        }

        $payload = $this->requestJson('POST', '/oauth/token', $requestPayload);

        return AccountToken::fromArray($payload);
    }

    public function readiness(): array
    {
        return $this->requestJson('GET', '/up');
    }

    /**
     * Resolve Account-owned display identity for app-local users.
     *
     * @param list<string> $pbbUserIds
     * @return array<string, AccountIdentity|null>
     */
    public function resolveIdentities(array $pbbUserIds): array
    {
        $ids = array_values(array_filter(array_map(
            fn (mixed $id): string => trim((string) $id),
            $pbbUserIds
        )));

        if ($ids === []) {
            return [];
        }

        $payload = $this->requestJson('POST', '/api/account-identities/resolve', [
            'pbb_user_ids' => $ids,
        ], true);

        $resolved = [];
        foreach ($payload['accounts'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $pbbUserId = trim((string) ($item['pbb_user_id'] ?? ''));
            if ($pbbUserId === '') {
                continue;
            }

            $resolved[$pbbUserId] = ($item['found'] ?? false)
                ? AccountIdentity::fromArray($item)
                : null;
        }

        return $resolved;
    }

    /**
     * Update Account-owned text identity fields from an app backend.
     *
     * Supported fields: name, email, mobile, account_session_id.
     */
    public function updateIdentity(string $pbbUserId, array $fields): AccountIdentity
    {
        $pbbUserId = trim($pbbUserId);
        if ($pbbUserId === '') {
            throw new AccountProtocolException('pbb_user_id is required for Account identity update.');
        }

        $payload = [];
        foreach (['name', 'email', 'mobile', 'account_session_id'] as $key) {
            if (array_key_exists($key, $fields)) {
                $payload[$key] = $fields[$key];
            }
        }

        if ($payload === []) {
            throw new AccountProtocolException('At least one Account identity field is required.');
        }

        $response = $this->requestJson('PATCH', '/api/account-identities/'.rawurlencode($pbbUserId), $payload, true);

        if (! isset($response['account']) || ! is_array($response['account'])) {
            throw new AccountProtocolException('Account identity update response is missing account payload.');
        }

        return AccountIdentity::fromArray($response['account']);
    }

    public function isReady(): bool
    {
        try {
            $readiness = $this->readiness();
        } catch (AccountException) {
            return false;
        }

        return ($readiness['status'] ?? null) === 'ok';
    }

    private function requestJson(string $method, string $path, ?array $payload = null, bool $trustedClientAuth = false): array
    {
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($trustedClientAuth) {
            $clientSecret = trim((string) $this->config->clientSecret);
            if ($clientSecret === '') {
                throw new AccountProtocolException('Account client secret is required for trusted client API calls.');
            }

            $headers['X-PBB-Account-Client-Id'] = $this->config->clientId;
            $headers['X-PBB-Account-Client-Secret'] = $clientSecret;
        }

        $response = $this->transport->request(
            $method,
            $this->config->baseUrl.$path,
            $headers,
            $body,
            [
                'timeout_seconds' => $this->config->timeoutSeconds,
                'ca_bundle' => $this->config->caBundle,
            ]
        );

        $decoded = $this->decodeJsonResponse($response['body']);
        $status = (int) $response['status'];

        if ($status < 200 || $status >= 300) {
            throw new AccountProtocolException($this->extractErrorMessage($decoded, $status));
        }

        return $decoded;
    }

    private function decodeJsonResponse(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new AccountProtocolException('Account returned an invalid JSON response.');
        }

        return $decoded;
    }

    private function extractErrorMessage(array $payload, int $status): string
    {
        if (isset($payload['message']) && is_string($payload['message'])) {
            return $payload['message'];
        }

        if (isset($payload['error']) && is_string($payload['error'])) {
            return $payload['error'];
        }

        $firstError = $this->firstValidationError($payload['errors'] ?? null);
        if ($firstError !== null) {
            return $firstError;
        }

        return "Account request failed with HTTP {$status}.";
    }

    private function firstValidationError(mixed $errors): ?string
    {
        if (! is_array($errors)) {
            return null;
        }

        foreach ($errors as $messages) {
            if (is_array($messages) && isset($messages[0]) && is_string($messages[0])) {
                return $messages[0];
            }
        }

        return null;
    }
}
