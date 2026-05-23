<?php

declare(strict_types=1);

final class RealtimeAdmission
{
    private RealtimeConfig $config;
    private RealtimeTokenBuilder $builder;

    public function __construct(RealtimeConfig $config, ?RealtimeTokenBuilder $builder = null)
    {
        $this->config = $config;
        $this->builder = $builder ?? new RealtimeTokenBuilder($config);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function buildAdmission(array $context): array
    {
        $room = RealtimeRoomHelper::normalizeChatRoom((string) ($context['room'] ?? ''));
        $claims = $this->buildClaimsForContext($context + ['room' => $room]);

        $token = $this->builder->sign($claims);

        $payload = [
            'token' => $token,
            'websocket_url' => $this->config->websocketUrl,
            'app_code' => $claims['app_code'],
            'project_code' => $claims['project_code'],
            'room' => $room,
            'expires_at' => gmdate('c', (int) $claims['exp']),
            'session' => [
                'token_id' => $claims['jti'],
                'user_id' => $claims['user_id'],
                'display_name' => $claims['display_name'],
                'capabilities' => $claims['capabilities'],
                'allowed_rooms' => $claims['allowed_rooms'],
                'allowed_room_prefixes' => $claims['allowed_room_prefixes'],
                'attachment_policy' => $claims['attachment_policy'],
            ],
        ];

        if ((bool) ($context['conference'] ?? false)) {
            $payload['call_room'] = RealtimeRoomHelper::buildCallRoomFromChatRoom($room);
            $payload['session']['call_room'] = $payload['call_room'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildClaimsForContext(array $context): array
    {
        if ((bool) ($context['conference'] ?? false)) {
            return $this->builder->forConferenceSession($context);
        }

        if ((bool) ($context['attachments'] ?? false)) {
            return $this->builder->forAttachmentSession($context);
        }

        if ((bool) ($context['presence'] ?? false)) {
            return $this->builder->forPresenceSession($context);
        }

        return $this->builder->forChatSession($context);
    }
}
