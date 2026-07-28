# PBB Hotline Community SDK Implementation Checklist

Status: initial implementation complete
Related proposal: [PBB Hotline Community SDK Proposal](pbb-hotline-community-sdk-proposal.md)

Implemented package location:

```text
packages/pbb-hotline-community-sdk/
```

Implemented files:

```text
packages/pbb-hotline-community-sdk/js/hotline-community.js
packages/pbb-hotline-community-sdk/demo/community-sdk.html
packages/pbb-hotline-community-sdk/README.md
```

## Product Boundary

- Hotline remains the source of truth for alert status and official public/community broadcasts.
- The SDK is read-only.
- Consuming apps decide how to render or react to alert status and broadcasts.
- SDK-owned Realtime is the default plug-and-play mode.
- Realtime transports updates but does not own alert or broadcast semantics.
- Helper may own visual widgets later, but the SDK core remains UI-free.
- Operator-only, command-only, admin, internal, team, support, incident, SITREP, and media payloads are out of scope.

## Completed Runtime Audit

- [x] Alert level source is `SettingsService::currentAlertLevel()`.
- [x] Valid current alert levels are `Normal`, `Elevated`, and `Critical`.
- [x] Alert changes publish through existing Realtime settings room behavior.
- [x] Public/community listeners use:

```text
hotline.settings.global
hotline.broadcast.global
```

- [x] Hotline can mint a narrow public/community Realtime admission token without a consuming-app secret.
- [x] Alert voice and audio graph style are intentionally not exposed in the initial public alert object.
- [x] Broadcast storage uses `command_broadcasts`.
- [x] Public/community broadcast visibility is limited to broadcasts targeting `citizen`, `caller`, `public`, or `community`.
- [x] Operator-only, command-only, admin, and internal broadcasts are excluded from the SDK bootstrap response.

## Completed Public REST Endpoints

- [x] Added read-only consolidated endpoint:

```text
GET /api/public/community-status
```

- [x] Response includes only minimal community signal data:
  - current alert status;
  - active community-visible broadcasts;
  - Realtime room/event metadata.
- [x] Response does not expose full settings, incidents, SITREPs, support requests, media refs, storage URLs, Realtime signing secret, backend secrets, or internal broadcast payloads.
- [x] Current implemented response shape:

```json
{
  "namespace": "pbb.hotline.community.v1",
  "generated_at": "2026-07-09T10:15:00+08:00",
  "alert": {
    "level": "Elevated",
    "description": "Heightened readiness is in effect due to increased local risk.",
    "room": "hotline.settings.global"
  },
  "broadcasts": [
    {
      "id": "7",
      "title": "Community Advisory",
      "message": "Evacuation center is open.",
      "tone": "warning",
      "audience": "community",
      "target_roles": ["citizen"],
      "published_at": "2026-07-09T10:10:00+08:00",
      "expires_at": "2026-07-09T12:10:00+08:00"
    }
  ],
  "realtime": {
    "admission_url": "https://hotline.pbb.ph/api/public/community-realtime",
    "rooms": [
      "hotline.settings.global",
      "hotline.broadcast.global"
    ],
    "event_types": [
      "hotline.alert_level.changed",
      "hotline.broadcast.created"
    ]
  }
}
```

- [x] Added read-only Realtime admission endpoint:

```text
GET /api/public/community-realtime
```

- [x] Token is narrow:
  - allows `session.connect`;
  - allows `room.join`;
  - allows only `hotline.settings.global` and `hotline.broadcast.global`;
  - does not allow publish capability;
  - does not allow incident, support request, SITREP, media, command, operator, admin, team, or internal rooms.
- [x] Endpoint does not require a consuming-app secret.
- [x] Endpoint returns `422` if Hotline Realtime signing is not configured.

## Completed SDK Package

- [x] Added source-only browser SDK:

```text
packages/pbb-hotline-community-sdk/js/hotline-community.js
```

- [x] Exports:

```js
HotlineCommunityClient
createHotlineCommunityClient
normalizeAlertStatus
normalizeBroadcastMessage
```

- [x] SDK is framework-agnostic.
- [x] SDK does not import Hotline app internals.
- [x] SDK does not import Helper UI.
- [x] SDK does not expose alert mutation methods.
- [x] SDK does not expose broadcast publishing methods.
- [x] SDK supports dependency injection for `fetchImpl`, `realtimeFactory`, optional `realtimeClient`, and optional `WebSocketImpl`.
- [x] SDK-owned Realtime is the default mode.
- [x] Advanced consumers may inject an existing Realtime client or factory.

## Completed SDK Behavior

- [x] `start()` loads current community status and connects Realtime by default.
- [x] `load()` fetches `/api/public/community-status`.
- [x] `connectRealtime()` fetches `/api/public/community-realtime`.
- [x] Browser WebSocket fallback appends the token as a query parameter.
- [x] `currentAlert()` returns the latest normalized alert.
- [x] `currentBroadcasts()` returns active normalized broadcasts.
- [x] `on(eventName, handler)` registers listeners.
- [x] `off(eventName, handler)` unregisters listeners.
- [x] `close()` closes owned transports when possible.
- [x] `handleRealtimeMessage(message)` accepts raw Realtime event objects or JSON strings.
- [x] Supports REST-only mode with `autoRealtime: false`.
- [x] Emits:

```text
community.loaded
alert.loaded
alert.changed
broadcast.received
broadcast.removed
```

## Completed Normalization

- [x] Alert normalization maps:

```text
Normal = 0
Elevated = 1
Critical = 2
```

- [x] Unknown alert levels are preserved with severity `-1`.
- [x] Broadcast normalization filters operator/admin/internal-only broadcasts.
- [x] Broadcast IDs are normalized to strings.
- [x] Broadcast body accepts `message` or `body`.
- [x] Broadcast audience is normalized to community-safe values.

## Completed Demo And Docs

- [x] Added SDK README:

```text
packages/pbb-hotline-community-sdk/README.md
```

- [x] Added browser demo:

```text
packages/pbb-hotline-community-sdk/demo/community-sdk.html
```

- [x] Updated management review index.
- [x] Updated Community SDK proposal with implemented package path and usage.

## Completed Tests

- [x] Added feature tests for `/api/public/community-status`.
- [x] Added feature tests for `/api/public/community-realtime`.
- [x] Added tests proving operator-only broadcasts are excluded.
- [x] Added tests proving narrow Realtime rooms/capabilities.
- [x] Added Playwright-backed SDK contract test.
- [x] Added npm script:

```text
npm run test:hotline-community-sdk
```

Validation performed for PR #81:

```text
php artisan test tests\Feature\Realtime\AdmissionTest.php tests\Feature\Public\CommunityStatusApiTest.php
npm run test:hotline-community-sdk
npm run build
git diff --check
```

## Current Known Limits

- Initial Realtime event support tracks the existing Hotline event names:

```text
hotline.alert_level.changed
hotline.broadcast.created
```

- Explicit broadcast updated/expired/retracted backend events are not yet implemented in Hotline. The SDK already accepts removed-event names for forward compatibility.
- Automatic Realtime admission refresh before token expiry is not implemented yet.
- The SDK does not render UI. Consuming apps own banners, badges, notifications, sounds, and local persistence.

## Future Enhancements

- Add backend broadcast lifecycle events for update, expiry, and retraction when Hotline gains those workflows.
- Add automatic Realtime token refresh and reconnect policy.
- Add optional Helper-owned visual components for alert banners or broadcast cards if multiple PBB apps converge on the same UI.
- Add a dedicated developer manual if downstream integration grows beyond the README.
