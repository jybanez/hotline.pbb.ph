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
        $this->stateStore->put($this->config->stateKey, $state);

        $params = array_merge($extraParams, [
            'client_id' => $this->config->clientId,
            'redirect_uri' => $this->config->redirectUri,
            'response_type' => 'code',
            'scope' => $this->config->scopesString(),
            'state' => $state,
        ]);

        return $this->config->baseUrl.'/oauth/authorize?'.http_build_query($params);
    }

    public function authorizeUrl(array $extraParams = []): string
    {
        return $this->authorizationUrl($extraParams);
    }

    public function handleCallback(array $query): AccountIdentity
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

        return $this->exchangeCode($code)->identity;
    }

    public function exchangeCode(string $code): AccountToken
    {
        $clientSecret = trim((string) $this->config->clientSecret);
        if ($clientSecret === '') {
            throw new AccountProtocolException('Account client secret is required for authorization code exchange.');
        }

        $payload = $this->requestJson('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->config->clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $this->config->redirectUri,
        ]);

        return AccountToken::fromArray($payload);
    }

    public function readiness(): array
    {
        return $this->requestJson('GET', '/up');
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

    private function requestJson(string $method, string $path, ?array $payload = null): array
    {
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);

        $response = $this->transport->request(
            $method,
            $this->config->baseUrl.$path,
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
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
