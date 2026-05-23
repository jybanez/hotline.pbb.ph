<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/pbb_realtime_backend_sdk.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);

    $config = new RealtimeConfig([
        'issuer' => 'pbb-hotline-backend',
        'audience' => 'pbb-realtime',
        'signing_secret' => $_ENV['REALTIME_TOKEN_SIGNING_SECRET'] ?? 'replace-me',
        'websocket_url' => $_ENV['REALTIME_WEBSOCKET_URL'] ?? 'wss://realtime.pbb.ph/realtime',
        'token_ttl_seconds' => 3600,
    ]);

    $admission = new RealtimeAdmission($config);

    // Product code should authenticate the current user and decide the
    // correct Realtime client/project scope before calling the SDK.
    $payload = $admission->buildAdmission([
        'app_code' => (string) ($input['app_code'] ?? ''),
        'project_code' => (string) ($input['project_code'] ?? ''),
        'user_id' => (string) ($input['user_id'] ?? ''),
        'display_name' => (string) ($input['display_name'] ?? ''),
        'room' => (string) ($input['room'] ?? ''),
        'conference' => (bool) ($input['conference'] ?? false),
        'presence' => (bool) ($input['presence'] ?? false),
        'attachments' => (bool) ($input['attachments'] ?? false),
        'capabilities' => [
            'session.connect',
            'room.join',
            'presence.publish',
            'presence.subscribe',
            'chat.publish',
            'chat.subscribe',
            'call.signal',
        ],
        'allowed_room_prefixes' => [
            'chat.thread.',
            'call.session.',
            'stream.session.',
        ],
        'attachment_policy' => [
            'max_attachment_count' => 6,
            'max_attachment_bytes' => 2 * 1024 * 1024,
            'max_total_bytes_per_message' => 6 * 1024 * 1024,
            'chunk_events_per_minute' => 180,
            'chunk_bytes_per_minute' => 12 * 1024 * 1024,
        ],
    ]);

    echo json_encode([
        'status' => true,
        'data' => $payload,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);

    echo json_encode([
        'status' => false,
        'message' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
