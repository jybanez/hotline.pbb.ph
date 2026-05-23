<?php

declare(strict_types=1);

final class RealtimeTokenBuilder
{
    private RealtimeConfig $config;
    private RealtimeClaimNormalizer $normalizer;

    public function __construct(RealtimeConfig $config, ?RealtimeClaimNormalizer $normalizer = null)
    {
        $this->config = $config;
        $this->normalizer = $normalizer ?? new RealtimeClaimNormalizer();
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function forChatSession(array $context): array
    {
        $room = RealtimeRoomHelper::normalizeChatRoom((string) ($context['room'] ?? ''));
        $allowedRooms = [$room];

        return $this->buildBaseClaims($context, $allowedRooms);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function forPresenceSession(array $context): array
    {
        $room = RealtimeRoomHelper::normalizeChatRoom((string) ($context['room'] ?? ''));
        $allowedRooms = [$room];
        $context['capabilities'] = $this->mergeCapabilities(
            is_array($context['capabilities'] ?? null) ? $context['capabilities'] : [],
            ['session.connect', 'room.join', 'presence.subscribe', 'presence.publish']
        );

        return $this->buildBaseClaims($context, $allowedRooms);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function forAttachmentSession(array $context): array
    {
        $room = RealtimeRoomHelper::normalizeChatRoom((string) ($context['room'] ?? ''));
        $allowedRooms = [$room];
        $context['capabilities'] = $this->mergeCapabilities(
            is_array($context['capabilities'] ?? null) ? $context['capabilities'] : [],
            ['session.connect', 'room.join', 'chat.publish', 'chat.subscribe']
        );

        return $this->buildBaseClaims($context, $allowedRooms);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function forConferenceSession(array $context): array
    {
        $chatRoom = RealtimeRoomHelper::normalizeChatRoom((string) ($context['room'] ?? ''));
        $callRoom = RealtimeRoomHelper::buildCallRoomFromChatRoom($chatRoom);
        $allowedRooms = [$chatRoom, $callRoom];

        return $this->buildBaseClaims($context, $allowedRooms);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            $this->base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $this->config->signingSecret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function buildSignedToken(array $claims): string
    {
        return $this->sign($this->normalizer->normalizeClaims($claims));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, string> $allowedRooms
     * @return array<string, mixed>
     */
    private function buildBaseClaims(array $context, array $allowedRooms): array
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->config->tokenTtlSeconds;
        $userId = trim((string) ($context['user_id'] ?? ''));
        $subject = trim((string) ($context['sub'] ?? ('session:' . $userId)));

        $claims = [
            'iss' => $this->config->issuer,
            'sub' => $subject,
            'aud' => $this->config->audience,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'jti' => trim((string) ($context['jti'] ?? ('rt_' . bin2hex(random_bytes(10))))),
            'project_code' => (string) ($context['project_code'] ?? ''),
            'app_code' => (string) ($context['app_code'] ?? ''),
            'user_id' => $userId,
            'display_name' => $context['display_name'] ?? null,
            'email' => $context['email'] ?? null,
            'roles' => is_array($context['roles'] ?? null) ? $context['roles'] : [],
            'capabilities' => $this->normalizer->normalizeCapabilities(
                is_array($context['capabilities'] ?? null) ? $context['capabilities'] : []
            ),
            'tenant_id' => $context['tenant_id'] ?? null,
            'org_id' => $context['org_id'] ?? null,
            'workspace_id' => $context['workspace_id'] ?? null,
            'allowed_rooms' => $allowedRooms,
            'allowed_room_prefixes' => is_array($context['allowed_room_prefixes'] ?? null)
                ? $context['allowed_room_prefixes']
                : [],
            'origin' => $context['origin'] ?? null,
            'attachment_policy' => is_array($context['attachment_policy'] ?? null)
                ? $context['attachment_policy']
                : [],
        ];

        return $this->normalizer->normalizeClaims($claims);
    }

    /**
     * @param array<int, mixed> $current
     * @param array<int, string> $required
     * @return array<int, string>
     */
    private function mergeCapabilities(array $current, array $required): array
    {
        return $this->normalizer->normalizeCapabilities(array_merge($current, $required));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
