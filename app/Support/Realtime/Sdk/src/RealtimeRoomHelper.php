<?php

declare(strict_types=1);

final class RealtimeRoomHelper
{
    public static function normalizeChatRoom(string $room): string
    {
        $trimmed = trim($room);

        if (str_starts_with($trimmed, 'chat.thread.')) {
            return $trimmed;
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $trimmed) ?: 'sandbox-room';
        $sanitized = trim($sanitized, '.-');

        return 'chat.thread.' . ($sanitized !== '' ? $sanitized : 'sandbox-room');
    }

    public static function buildCallRoomFromChatRoom(string $room): string
    {
        $trimmed = trim($room);

        if (str_starts_with($trimmed, 'call.session.')) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, 'chat.thread.')) {
            $trimmed = substr($trimmed, strlen('chat.thread.'));
        }

        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $trimmed) ?: 'sandbox-room';
        $sanitized = trim($sanitized, '.-');

        return 'call.session.' . ($sanitized !== '' ? $sanitized : 'sandbox-room');
    }
}
