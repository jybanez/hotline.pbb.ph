# PBB Realtime Backend SDK

Small plain-PHP helper library for product backends that need to issue Realtime admission payloads for frontend clients.

## What It Is

This backend SDK helps a PHP project:

- normalize Realtime claims
- sign Realtime JWTs
- derive chat and call rooms
- return frontend-facing admission payloads

It is intentionally:

- framework-agnostic
- small
- easy to vendor
- transport-focused

## Files

- `pbb_realtime_backend_sdk.php`
  - single entry point
- `src/`
  - implementation classes
- `examples/admission-endpoint.php`
  - minimal example endpoint
- `examples/chat-terminal-admission.php`
  - chat-focused admission example
- `examples/operator-console-admission.php`
  - operator console admission example
- `examples/conference-admission.php`
  - conference admission example

## Quick Start

```php
<?php

require_once __DIR__ . '/pbb_realtime_backend_sdk.php';

$config = new RealtimeConfig([
    'issuer' => 'pbb-hotline-backend',
    'audience' => 'pbb-realtime',
    'signing_secret' => 'replace-me',
    'websocket_url' => 'wss://realtime.pbb.ph/realtime',
    'token_ttl_seconds' => 3600,
]);

$admission = new RealtimeAdmission($config);

$payload = $admission->buildAdmission([
    'app_code' => 'clt_hotline',
    'project_code' => 'prj_hotline_operator',
    'user_id' => 'operator_123',
    'display_name' => 'Operator 123',
    'room' => 'incident-2026-0001',
    'presence' => true,
    'attachments' => true,
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
```

## Main Classes

- `RealtimeConfig`
  - backend SDK configuration
- `RealtimeClaimNormalizer`
  - claim normalization helpers
- `RealtimeTokenBuilder`
  - JWT claim creation and HS256 signing
- `RealtimeAdmission`
  - frontend-facing admission payload builder
- `RealtimeRoomHelper`
  - room normalization helpers
- `RealtimeConferenceHelper`
  - conference defaults and guardrails

## Output

`RealtimeAdmission::buildAdmission()` returns an array like:

- `token`
- `websocket_url`
- `app_code`
- `project_code`
- `room`
- `expires_at`
- `session`
- optional `call_room`

That payload is what a frontend app passes into the Realtime frontend SDK.

## Important Boundary

The backend SDK does not decide business authorization.

Your product backend must already know:

- who the user is
- which Realtime client code applies
- which project scope code applies
- which room the user should join
- which transport behaviors are allowed

The backend SDK takes those decisions and turns them into a Realtime-compatible admission payload.

For browser-originated media chunk transport, issue tokens with:

- the correct room prefix for your flow, for example `call.session.` or `stream.session.`
- a project scope that has media ingest enabled/configured in Realtime

Then the frontend can publish `media.chunk.publish` over websocket using the JS SDK helper `buildMediaChunkPublishPayload(...)`.

The immediate `media.chunk.publish` ack only means Realtime queued the chunk for downstream forwarding. Keep browser-local retry data until the room emits `media.chunk.forwarded`; treat `media.chunk.failed` as the downstream ingest failure signal.

Binary browser media transport is additive when the Realtime project scope enables it. The browser flow is `media.chunk.prepare` followed by a Realtime-framed binary websocket message, then sender-scoped `media.chunk.queued`. The existing `media.chunk.forwarded` / `media.chunk.failed` outcome events remain the downstream persistence boundary.

## More Docs

See:

- `docs/pbb-realtime-backend-sdk-quickstart.md`
- `docs/pbb-realtime-backend-sdk-hotline-example.md`
- `docs/pbb-realtime-backend-sdk-arguments-reference.md`
- `docs/pbb-realtime-backend-sdk-return-values-reference.md`
