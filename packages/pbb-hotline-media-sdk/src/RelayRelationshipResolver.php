<?php

namespace Pbb\Hotline\Media;

final class RelayRelationshipResolver
{
    private const RELAY_BASE_URL = 'https://relay.pbb.ph';

    public function __construct(
        private readonly HttpTransportInterface $transport = new NativeHttpTransport(),
    ) {}

    /**
     * @return array{source_base_url:string,token:string,local_hub_id:string,relationship_direction?:string,version?:string|null,issued_at?:string|null,rotated_at?:string|null}
     */
    public function resolve(string $sourceHubId, string $relayToken, string $purpose = 'hotline_media'): array
    {
        $sourceHubId = trim($sourceHubId);
        $relayToken = trim($relayToken);

        if ($sourceHubId === '') {
            throw new \InvalidArgumentException('source_hub_id is required for Relay relationship resolution.');
        }

        if ($relayToken === '') {
            throw new \InvalidArgumentException('relay_token is required for Relay relationship resolution.');
        }

        $localHubId = $this->localHubId();
        $response = $this->transport->request(
            'POST',
            self::RELAY_BASE_URL.'/api/v1/relationships/resolve',
            [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Connection' => 'close',
                'X-Relay-Key' => $relayToken,
            ],
            json_encode([
                'source_hub_id' => $sourceHubId,
                'target_hub_id' => $localHubId,
                'purpose' => $purpose,
            ], JSON_UNESCAPED_SLASHES),
        );

        $payload = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || ! is_array($payload) || ($payload['valid'] ?? false) !== true) {
            $message = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';

            throw new \RuntimeException($message !== '' ? $message : 'Relay relationship resolution failed.');
        }

        $token = $this->text($payload['token'] ?? null);
        $domain = $this->text(
            $payload['source_hub_domain']
                ?? $payload['source_domain']
                ?? $payload['domain']
                ?? $payload['source_base_url']
                ?? $payload['source_hotline_url']
                ?? null
        );

        if ($token === null) {
            throw new \RuntimeException('Relay relationship response did not include a credential token.');
        }

        if ($domain === null) {
            throw new \RuntimeException('Relay relationship response did not include a source domain.');
        }

        return [
            'source_base_url' => $this->landingGatewayBaseUrl($domain),
            'token' => $token,
            'local_hub_id' => $localHubId,
            'relationship_direction' => $this->text($payload['relationship_direction'] ?? null),
            'version' => $this->text($payload['version'] ?? null),
            'issued_at' => $this->text($payload['issued_at'] ?? null),
            'rotated_at' => $this->text($payload['rotated_at'] ?? null),
        ];
    }

    private function localHubId(): string
    {
        $response = $this->transport->request(
            'GET',
            self::RELAY_BASE_URL.'/hub.json',
            [
                'Accept' => 'application/json',
                'Connection' => 'close',
            ],
        );

        $payload = json_decode($response['body'], true);
        $hubId = is_array($payload) ? $this->text($payload['hub_id'] ?? null) : null;

        if ($response['status'] < 200 || $response['status'] >= 300 || $hubId === null) {
            throw new \RuntimeException('Unable to resolve local PBB hub id from https://relay.pbb.ph/hub.json.');
        }

        return $hubId;
    }

    private function landingGatewayBaseUrl(string $value): string
    {
        $baseUrl = preg_match('/^https?:\/\//i', $value) === 1
            ? rtrim($value, '/')
            : 'https://'.rtrim($value, '/');

        if (preg_match('/\/hotline$/i', $baseUrl) === 1) {
            return $baseUrl;
        }

        return $baseUrl.'/hotline';
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
