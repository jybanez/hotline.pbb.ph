<?php

declare(strict_types=1);

final class RealtimeConfig
{
    public string $issuer;
    public string $audience;
    public string $signingSecret;
    public string $websocketUrl;
    public int $tokenTtlSeconds;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->issuer = trim((string) ($config['issuer'] ?? ''));
        $this->audience = trim((string) ($config['audience'] ?? ''));
        $this->signingSecret = (string) ($config['signing_secret'] ?? '');
        $this->websocketUrl = trim((string) ($config['websocket_url'] ?? ''));
        $this->tokenTtlSeconds = max(60, (int) ($config['token_ttl_seconds'] ?? 900));

        foreach ([
            'issuer' => $this->issuer,
            'audience' => $this->audience,
            'signing_secret' => $this->signingSecret,
            'websocket_url' => $this->websocketUrl,
        ] as $key => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("Missing required Realtime config value: {$key}.");
            }
        }
    }
}
