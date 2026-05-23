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

// Example: operator console admission where presence, chat, attachments,
// and browser-originated media chunk transport are needed. Realtime should
// have media ingest enabled on this project scope.
$payload = $admission->buildAdmission([
    'app_code' => 'clt_project',
    'project_code' => 'prj_operator_console',
    'user_id' => 'operator_123',
    'display_name' => 'Operator 123',
    'room' => 'dispatch-incident-2026-0001',
    'presence' => true,
    'attachments' => true,
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

header('Content-Type: application/json');
echo json_encode(['status' => true, 'data' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
