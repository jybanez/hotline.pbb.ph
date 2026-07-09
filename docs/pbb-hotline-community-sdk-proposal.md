# PBB Hotline Community SDK Proposal

Status: proposal  
Owner: PBB Hotline Beta  
Primary consumers: PBB Support, Utility/Vena, Landing, MapServer, Relay dashboards, future PBB apps  
Last updated: 2026-07-09

## Purpose

The PBB ecosystem is growing, but Hotline remains the local source of truth for community alert posture and official community-facing Hotline broadcasts.

Other PBB apps need a consistent way to:

- know when the hub/community alert level changes;
- receive public/non-operator-exclusive Hotline broadcast messages;
- adapt their surfaces without copying Hotline internals.

This proposal defines a small read-only JavaScript SDK that lets other PBB apps consume Hotline community signals in a stable way.

## Product Boundary

Hotline owns:

- current alert level;
- alert label/severity semantics;
- public/community broadcast publishing;
- audience classification for broadcasts;
- alert and broadcast update authority through Command/Admin;
- REST bootstrap endpoints;
- Realtime publication;
- SDK contract and source package.

Consuming apps own:

- local user authorization;
- how alert state and broadcasts affect their UI;
- local notification policy;
- local persistence/cache, if any;
- whether to show banners, badges, colors, sounds, filters, maps, or escalation workflows.

Realtime owns:

- event transport;
- room membership;
- delivery and reconnect behavior.

Helper owns:

- optional UI components if cross-app rendering widgets are later needed.

The SDK must be read-only. It must not expose methods that change Hotline alert state or publish Hotline broadcasts.

## Community Signal Types

First-pass SDK scope:

```text
alert.status
broadcast.public
```

`alert.status` is the current community posture.

`broadcast.public` is an official Hotline message intended for citizen/community-facing PBB apps or broad public-awareness surfaces.

## First-Pass Goals

- Provide one canonical JavaScript client for Hotline community signals.
- Bootstrap current alert status through REST.
- Bootstrap active public/community broadcasts through REST.
- Listen for Realtime alert-change and broadcast events.
- Normalize alert values into a small stable object.
- Normalize broadcast messages into a small stable object.
- Provide ergonomic event callbacks for app surfaces.
- Keep UI rendering app-owned.
- Avoid coupling consumers to Hotline settings table names or broadcast storage internals.

## Non-Goals

- No alert-level mutation API.
- No broadcast publishing API.
- No command/admin authorization logic.
- No operator-only or command-only broadcasts in the public SDK.
- No incident, SITREP, support request, or media payload delivery.
- No Helper UI dependency in SDK core.
- No global browser styling injected by the SDK.
- No replacement for Realtime SDK.
- No cross-hub aggregation in first pass.
- No alert or broadcast analytics in first pass.

## Proposed Package

```text
packages/pbb-hotline-community-sdk/
```

Suggested package shape:

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

The package should be source/dev scope like other Hotline SDK packages. Installer bundles should exclude it unless a consumer explicitly needs source SDK files at runtime.

## Alert Status Contract

Canonical alert object:

```json
{
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
}
```

Required fields:

- `level`
- `label`
- `severity`
- `updated_at`
- `source.app`

Optional fields:

- `source.hub_id`
- `source.deployment`
- `presentation.voice`
- `presentation.audio_graph_style`

Allowed `level` values:

```text
normal
elevated
critical
```

Suggested severity mapping:

```text
normal = 0
elevated = 1
critical = 2
```

The SDK should tolerate unknown future levels by preserving the raw value and assigning a conservative severity fallback.

## Public Broadcast Contract

Canonical broadcast object:

```json
{
  "id": "bcast_01kz...",
  "level": "info",
  "title": "Evacuation advisory",
  "body": "Residents near the river are advised to move to higher ground.",
  "audience": "public",
  "priority": 1,
  "created_at": "2026-07-09T11:30:00+08:00",
  "updated_at": "2026-07-09T11:30:00+08:00",
  "expires_at": "2026-07-09T18:00:00+08:00",
  "source": {
    "app": "hotline",
    "hub_id": "13",
    "deployment": "barangay"
  }
}
```

Required fields:

- `id`
- `level`
- `title`
- `body`
- `audience`
- `created_at`
- `source.app`

Optional fields:

- `priority`
- `updated_at`
- `expires_at`
- `source.hub_id`
- `source.deployment`

Allowed first-pass `audience` values:

```text
public
community
```

Excluded from this SDK:

```text
operator
command
admin
internal
support
team
```

Allowed first-pass `level` values:

```text
info
advisory
warning
urgent
```

Broadcasts should be plain text or sanitized presentation-safe text. The SDK should not execute embedded HTML or scripts.

## REST Bootstrap

Recommended consolidated bootstrap endpoint:

```text
GET /api/public/community-status
```

Expected response:

```json
{
  "ok": true,
  "alert": {
    "level": "elevated",
    "label": "Elevated",
    "severity": 1,
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

Optional narrower endpoints:

```text
GET /api/public/alert-status
GET /api/public/broadcasts
```

The consolidated endpoint is preferred for consumer apps because it avoids a multi-request startup path.

The endpoint should be public/read-only and safe for sibling PBB apps to call. It must not expose secrets, user data, incident records, SITREP payloads, support requests, media refs, or operator-only broadcasts.

## Realtime Events

Proposed event types:

```text
hotline.alert.changed
hotline.broadcast.published
hotline.broadcast.updated
hotline.broadcast.expired
hotline.broadcast.retracted
```

Suggested rooms:

```text
hotline.settings.global
hotline.broadcast.global
```

Alert payload:

```json
{
  "alert": {
    "level": "critical",
    "label": "Critical",
    "severity": 2,
    "updated_at": "2026-07-09T10:20:00+08:00",
    "source": {
      "app": "hotline",
      "hub_id": "13",
      "deployment": "barangay"
    }
  }
}
```

Broadcast payload:

```json
{
  "broadcast": {
    "id": "bcast_01kz...",
    "level": "warning",
    "title": "Flood warning",
    "body": "Avoid low-lying roads until further notice.",
    "audience": "public",
    "priority": 2,
    "created_at": "2026-07-09T11:45:00+08:00",
    "expires_at": "2026-07-09T18:00:00+08:00",
    "source": {
      "app": "hotline",
      "hub_id": "13",
      "deployment": "barangay"
    }
  }
}
```

If Hotline already emits a settings/broadcast event for alert or broadcast changes, the SDK can adapt to the existing event as long as the public SDK events remain stable.

## SDK API Sketch

```js
import { HotlineCommunityClient } from './src/HotlineCommunityClient.js';

const client = new HotlineCommunityClient({
  hotlineBaseUrl: 'https://hotline.pbb.ph',
  realtimeClient,
});

await client.bootstrap();

client.on('alert.changed', (alert) => {
  console.log(alert.level);
});

client.on('broadcast.received', (broadcast) => {
  console.log(broadcast.title);
});
```

Constructor options:

```js
{
  hotlineBaseUrl,
  realtimeClient,
  rooms,
  fetchImpl,
  logger,
  autoSubscribe,
  includeExpired
}
```

Events:

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

Methods:

```js
bootstrap()
subscribe()
unsubscribe()
currentAlert()
activeBroadcasts()
getBroadcast(id)
on(eventName, handler)
off(eventName, handler)
isNormal()
isElevated()
isCritical()
severityRank()
alertCssClass()
broadcastCssClass(broadcast)
```

The SDK may expose helper functions, but they must be pure and framework-agnostic.

## Consumer Behavior Examples

Support:

- highlight urgent support requests when Hotline is critical;
- show current hub alert in support strategy views;
- show official public Hotline advisories beside area context.

Utility/Vena:

- change map layer emphasis during elevated/critical periods;
- mark utility missions created while Hotline is elevated/critical;
- show hub alert status beside live Hotline incidents;
- display public Hotline advisories relevant to utility routing/planning.

Landing:

- show public app shortcuts with alert-aware badges;
- show official Hotline public broadcasts on the hub landing page;
- avoid exposing operational details.

MapServer:

- optionally style hub boundary overlays based on alert level when supplied by a consuming app;
- optionally display broadcast markers or panels if a consuming app passes broadcast data.

Citizen-facing PBB apps:

- show official Hotline public/community broadcasts;
- avoid showing operator-only dispatch instructions.

## Security And Privacy

Alert status and public broadcasts are community posture signals, not user secrets. The public endpoint should still be minimal:

- no user/session data;
- no incident counts unless explicitly approved later;
- no support request data;
- no SITREP payload;
- no media refs;
- no tokens;
- no raw settings dump;
- no operator-only or command-only broadcast payloads.

The SDK should not require browser credentials for first-pass read access. If a future deployment needs private community signals, a separate authenticated endpoint can be added without breaking the public SDK object shape.

## Broadcast Filtering Rules

The REST endpoint and SDK should only expose broadcasts that are:

- active or not expired;
- intended for `public` or `community` audience;
- not marked internal/operator/command/admin/support/team;
- not retracted;
- text-safe for broad PBB app display.

The SDK should defensively filter unsupported audience values even if the endpoint accidentally returns them.

## Failure Behavior

On REST failure:

- SDK emits `community.error`;
- `currentAlert()` returns the last known alert if available;
- `activeBroadcasts()` returns the last known broadcast list if available;
- if there is no last known alert, SDK may return a neutral `unknown` state.

On Realtime disconnect:

- SDK keeps last known alert and broadcast list;
- emits reconnect/error events if the underlying realtime client exposes them;
- does not repeatedly fetch in a tight loop.

On broadcast expiry:

- SDK should remove expired broadcasts from `activeBroadcasts()`;
- emit `broadcast.expired`;
- keep no hidden archive unless the consuming app owns one.

## Versioning

Initial SDK version:

```text
0.1.0
```

Backward-compatible additions:

- new optional fields;
- new helper methods;
- support for additional Realtime event aliases;
- additional broadcast levels or audiences, if consumers can safely ignore unsupported values.

Breaking changes:

- removing alert `level`, `label`, `severity`, or `updated_at`;
- removing broadcast `id`, `level`, `title`, `body`, `audience`, or `created_at`;
- changing allowed alert-level semantics;
- exposing operator-only broadcasts through public/community APIs;
- making read access require authentication without a compatibility path.

## Open Questions

- Should the package filename be changed immediately from alert SDK to community SDK, or should the proposal filename remain for continuity until implementation starts?
- Should the first endpoint be `/api/public/community-status` or another namespace aligned with Landing public gateways?
- Does Hotline already have broadcast persistence suitable for public/community filtering, or does first pass need a new public-broadcast read model?
- Should broadcast `body` support plain text only, Markdown subset, or sanitized HTML?
- Should alert status include hub identity from Relay `/hub.json`, or only app identity in first pass?
- Should the SDK live as plain ES module only, or also ship a bundled browser file?
- Should Helper later provide shared visual alert/broadcast components that consume this SDK?
