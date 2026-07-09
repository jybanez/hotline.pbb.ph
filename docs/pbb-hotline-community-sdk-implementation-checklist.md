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
- Helper may own visual widgets later, but the SDK core must remain UI-free.
- Operator-only, command-only, admin, internal, team, support, incident, SITREP, and media payloads are out of scope.

## 1. Current Runtime Audit

- [ ] Locate the current Hotline alert-level source in settings/runtime services.
- [ ] Confirm all valid current alert levels.
- [ ] Confirm where Command/Admin updates alert level.
- [ ] Confirm the current Realtime event emitted when alert level changes.
- [ ] Confirm which Realtime room consumers can join for alert updates.
- [ ] Confirm how Hotline can mint a narrow public/community Realtime admission token without a consuming-app secret.
- [ ] Confirm whether alert voice and audio graph style should be exposed in the public alert object.
- [ ] Locate current Hotline broadcast storage/publishing logic.
- [ ] Classify existing broadcast audiences and identify which values are safe for public/community consumers.
- [ ] Confirm whether current broadcast payloads can be safely exposed as plain text.
- [ ] Confirm current Realtime event emitted when a broadcast is published, updated, expired, or retracted.

## 2. REST Bootstrap Endpoint

- [ ] Add public read-only consolidated endpoint:

```text
GET /api/public/community-status
```

- [ ] Return only minimal community signal data:
  - current alert status;
  - active public/community broadcasts.
- [ ] Do not expose full settings.
- [ ] Do not expose users, incidents, SITREPs, support requests, media refs, storage URLs, tokens, operator-only broadcasts, or internal broadcast payloads.
- [ ] Normalize response to:

```json
{
  "ok": true,
  "alert": {
    "level": "normal",
    "label": "Normal",
    "severity": 0,
    "updated_at": "2026-07-09T10:15:00+08:00",
    "source": {
      "app": "hotline",
      "hub_id": "13",
      "deployment": "barangay"
    },
    "presentation": {
      "voice": "default",
      "audio_graph_style": "tsunami"
    }
  },
  "broadcasts": [
    {
      "id": "bcast_01kz...",
      "level": "advisory",
      "title": "Evacuation advisory",
      "body": "Residents near the river are advised to move to higher ground.",
      "audience": "public",
      "priority": 1,
      "created_at": "2026-07-09T11:30:00+08:00",
      "expires_at": "2026-07-09T18:00:00+08:00",
      "source": {
        "app": "hotline",
        "hub_id": "13",
        "deployment": "barangay"
      }
    }
  ]
}
```

- [ ] Optional separate endpoint only if needed:

```text
GET /api/public/alert-status
GET /api/public/broadcasts
```

- [ ] Add tests for normal, elevated, and critical alert output.
- [ ] Add tests for active public/community broadcast output.
- [ ] Add tests excluding expired, retracted, operator-only, command-only, admin, internal, team, and support broadcasts.
- [ ] Add tests proving sensitive settings and operational records are not present.

## 3. Public/Community Realtime Admission

- [ ] Add public read-only Realtime admission endpoint:

```text
GET /api/public/community-realtime
```

- [ ] Return:

```json
{
  "ok": true,
  "websocket_url": "wss://realtime.pbb.ph/realtime",
  "token": "public-community-scoped-token",
  "rooms": [
    "hotline.settings.global",
    "hotline.broadcast.global"
  ],
  "expires_at": "2026-07-09T12:15:00+08:00"
}
```

- [ ] Token must be read/listen only.
- [ ] Token must allow only public/community alert and broadcast rooms.
- [ ] Token must not allow publish capability.
- [ ] Token must not allow incident, support request, SITREP, media, command, operator, admin, team, or internal rooms.
- [ ] Endpoint must not require a consuming-app secret.
- [ ] Add tests proving the token/rooms are narrow.
- [ ] Add tests proving no sensitive settings are exposed.

## 4. Realtime Event Contract

- [ ] Reuse existing alert-change and broadcast Realtime publishing if already stable.
- [ ] If needed, add or document canonical event types:

```text
hotline.alert.changed
hotline.broadcast.published
hotline.broadcast.updated
hotline.broadcast.expired
hotline.broadcast.retracted
```

- [ ] Use the same normalized alert object as REST.
- [ ] Use the same normalized broadcast object as REST.
- [ ] Keep alert event publishing tied to successful alert-level changes only.
- [ ] Keep broadcast event publishing tied to approved public/community broadcast lifecycle changes only.
- [ ] Add tests or contract coverage for emitted payload shapes.
- [ ] Document room names used for alert and broadcast updates.
- [ ] Ensure unsupported audiences are not published to public/community Realtime channels.

## 5. SDK Package

- [ ] Create source package:

```text
packages/pbb-hotline-community-sdk/
```

- [ ] Add package files:

```text
packages/pbb-hotline-community-sdk/
  src/
    HotlineCommunityClient.js
    normalizeAlertStatus.js
    normalizeBroadcastMessage.js
  demo/
    community-sdk.html
  docs/
    developer-manual.md
  README.md
  package.json
```

- [ ] Keep SDK framework-agnostic.
- [ ] Do not import Hotline app internals.
- [ ] Do not import Helper in SDK core.
- [ ] Do not expose alert mutation methods.
- [ ] Do not expose broadcast publishing methods.
- [ ] Use dependency injection for `fetchImpl`, `realtimeFactory`, and optional `realtimeClient` for tests.
- [ ] Keep `realtimeClient` as an optional advanced override, not a required consumer dependency.

## 6. SDK Client Behavior

- [ ] Implement `start()` to run bootstrap and SDK-owned Realtime connection by default.
- [ ] Implement `bootstrap()` to fetch current community status.
- [ ] Implement `connectRealtime()` to call `/api/public/community-realtime` and create the SDK-owned socket.
- [ ] Implement `subscribe()` to listen for Realtime alert and broadcast events.
- [ ] Implement `unsubscribe()`.
- [ ] Implement `currentAlert()`.
- [ ] Implement `activeBroadcasts()`.
- [ ] Implement `getBroadcast(id)`.
- [ ] Implement event emitter methods:

```text
on(eventName, handler)
off(eventName, handler)
```

- [ ] Emit:

```text
community.loaded
alert.loaded
alert.changed
broadcast.loaded
broadcast.received
broadcast.updated
broadcast.expired
broadcast.retracted
community.error
community.reconnected
```

- [ ] Do not emit duplicate `alert.changed` if the normalized alert state has not changed.
- [ ] Do not emit duplicate broadcast events if the normalized broadcast has not changed.
- [ ] Preserve last known alert and active broadcast list on network or Realtime failure.
- [ ] Refresh admission when the SDK-owned token expires.
- [ ] Remove broadcasts from `activeBroadcasts()` on expiry/retraction.
- [ ] Support REST-only mode when `autoRealtime` is `false`.
- [ ] Use a supplied `realtimeClient` only when advanced consumers provide one.

## 7. Normalization Helpers

- [ ] Normalize alert level names to lowercase.
- [ ] Map alert severities:

```text
normal = 0
elevated = 1
critical = 2
```

- [ ] Preserve unknown future alert levels as raw strings.
- [ ] Normalize broadcast levels:

```text
info
advisory
warning
urgent
```

- [ ] Preserve unknown future broadcast levels as raw strings, but assign safe fallback priority.
- [ ] Normalize broadcast audiences and filter unsupported public SDK audiences.
- [ ] Ensure broadcast title/body are strings and safe for consumer rendering as text.
- [ ] Provide helper methods:

```text
isNormal()
isElevated()
isCritical()
severityRank()
alertCssClass()
broadcastCssClass(broadcast)
```

- [ ] Keep helper methods pure and deterministic.

## 8. Demo And Documentation

- [ ] Add browser demo page:

```text
packages/pbb-hotline-community-sdk/demo/community-sdk.html
```

- [ ] Demo should:
  - fetch current community status;
  - show normalized alert JSON;
  - show active public/community broadcasts;
  - show alert helper output;
  - connect to Realtime through the SDK-owned default path;
  - demonstrate REST-only mode with `autoRealtime=false`;
  - not require authenticated Hotline user session.

- [ ] Add developer manual:

```text
packages/pbb-hotline-community-sdk/docs/developer-manual.md
```

- [ ] Include examples for Support, Utility/Vena, Landing, MapServer, and citizen-facing PBB apps.
- [ ] Document that consumers need only `hotlineBaseUrl` for the default plug-and-play path.
- [ ] Document `realtimeClient` as an optional advanced override.
- [ ] Document read-only boundary clearly.
- [ ] Document unsupported broadcast audiences clearly.

## 9. Tests

- [ ] Unit tests for `normalizeAlertStatus`.
- [ ] Unit tests for `normalizeBroadcastMessage`.
- [ ] Unit tests for client `start()` success.
- [ ] Unit tests for client bootstrap success.
- [ ] Unit tests for SDK-owned Realtime admission and connection.
- [ ] Unit tests for bootstrap failure and last-known fallback.
- [ ] Unit tests for REST-only mode.
- [ ] Unit tests for Realtime `alert.changed`.
- [ ] Unit tests for Realtime broadcast publish/update/expire/retract events.
- [ ] Unit tests for duplicate suppression.
- [ ] Unit tests proving no mutation/publish API exists.
- [ ] Feature test for REST endpoint shape.
- [ ] Feature test for public/community Realtime admission shape.
- [ ] Feature test proving sensitive settings are not exposed.
- [ ] Feature test proving non-public broadcast audiences are excluded.

## 10. Packaging Boundary

- [ ] Keep SDK source/dev scope unless a consuming app explicitly vendors it.
- [ ] Do not build a Hotline installer bundle from the feature branch.
- [ ] If package metadata changes affect bundle rules, update `release.json` only after review.
- [ ] Main-built bundle handoff to Kit happens only after merge and explicit approval.

## 11. Cross-Team Coordination

- [ ] Inform Support about the read-only Community SDK once the branch is ready.
- [ ] Inform Utility/Vena about the normalized alert/broadcast objects and intended map use.
- [ ] Ask Realtime for confirmation if a new canonical event type or room is needed.
- [ ] Ask Helper only if shared visual alert/broadcast components are requested later.
- [ ] Ask Landing only if public gateway routing is needed for cross-app community bootstrap.

## Acceptance Criteria

- [ ] Other PBB apps can fetch current alert status without knowing Hotline settings internals.
- [ ] Other PBB apps can fetch active public/community broadcasts without accessing Hotline broadcast internals.
- [ ] Other PBB apps can receive live alert and broadcast changes through the SDK.
- [ ] SDK does not mutate alert state.
- [ ] SDK does not publish broadcasts.
- [ ] SDK does not render UI or inject styles.
- [ ] Sensitive settings and operational records are not exposed.
- [ ] Operator-only, command-only, admin, internal, support, team, incident, SITREP, and media payloads are not exposed.
- [ ] Tests cover REST endpoint, normalization, Realtime client behavior, and audience filtering.
- [ ] Docs and demo are usable by a consuming app developer.
