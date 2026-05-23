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

// Example: small-group mesh conference admission.
// This returns both the normalized chat room and the derived call room.
// Realtime media ingest, when needed, should be enabled on this project scope.
$payload = $admission->buildAdmission([
    'app_code' => 'clt_project',
    'project_code' => 'prj_hotline_operator',
    'user_id' => 'operator_123',
    'display_name' => 'Operator 123',
    'room' => 'call-incident-2026-0001',
    'presence' => true,
    'conference' => true,
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
]);

header('Content-Type: application/json');
echo json_encode(['status' => true, 'data' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
