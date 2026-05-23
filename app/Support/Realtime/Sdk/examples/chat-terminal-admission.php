<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/pbb_realtime_backend_sdk.php';

$config = new RealtimeConfig([
    'issuer' => 'pbb-project-backend',
    'audience' => 'pbb-realtime',
    'signing_secret' => $_ENV['REALTIME_TOKEN_SIGNING_SECRET'] ?? 'replace-me',
    'websocket_url' => $_ENV['REALTIME_WEBSOCKET_URL'] ?? 'wss://realtime.pbb.ph/realtime',
    'token_ttl_seconds' => 1800,
]);

$admission = new RealtimeAdmission($config);

// Example: chat terminal admission for a single operator/user terminal.
// Product code should already know the correct client, project scope, and room.
$payload = $admission->buildAdmission([
    'app_code' => 'clt_project',
    'project_code' => 'prj_chat_terminal',
    'user_id' => 'user_123',
    'display_name' => 'Operator 123',
    'room' => 'incident-room-2026-0001',
    'presence' => true,
    'capabilities' => [
        'session.connect',
        'room.join',
        'presence.publish',
        'presence.subscribe',
        'chat.publish',
        'chat.subscribe',
    ],
    'allowed_room_prefixes' => [
        'chat.thread.',
    ],
]);

header('Content-Type: application/json');
echo json_encode(['status' => true, 'data' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
