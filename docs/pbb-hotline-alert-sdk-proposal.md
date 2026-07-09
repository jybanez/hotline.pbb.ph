# PBB Hotline Alert SDK Proposal

Status: proposal  
Owner: PBB Hotline Beta  
Primary consumers: PBB Support, Utility/Vena, Landing, MapServer, Relay dashboards, future PBB apps  
Last updated: 2026-07-09

## Purpose

The PBB ecosystem is growing, but Hotline remains the local source of truth for community alert posture. Other PBB apps need a consistent way to know when the hub/community alert level changes so they can adapt their surfaces, workflows, and visual priority without copying Hotline internals.

This proposal defines a small JavaScript SDK that lets other PBB apps consume Hotline alert state in a stable, read-only way.

## Product Boundary

Hotline owns:

- current alert level;
- alert label/severity semantics;
- alert update authority through Command/Admin;
- REST bootstrap endpoint;
- Realtime alert-change publication;
- SDK contract and source package.

Consuming apps own:

- local user authorization;
- how alert state affects their UI;
- any local persistence/cache;
- whether to show banners, badges, colors, sounds, filters, or escalation workflows.

Realtime owns:

- event transport;
- room membership;
- delivery and reconnect behavior.

Helper owns:

- optional UI components if cross-app rendering widgets are later needed.

The SDK must be read-only. It must not expose a method that changes Hotline alert state.

## First-Pass Goals

- Provide one canonical JavaScript client for current Hotline alert status.
- Bootstrap current status through REST.
- Listen for Realtime alert-change events.
- Normalize alert values into a small stable object.
- Provide ergonomic event callbacks for app surfaces.
- Keep UI rendering app-owned.
- Avoid coupling consumers to Hotline settings table names.

## Non-Goals

- No alert-level mutation API.
- No command/admin authorization logic.
- No Helper UI dependency in the SDK core.
- No global browser styling injected by the SDK.
- No replacement for Realtime SDK.
- No cross-hub aggregation in first pass.
- No alert history/analytics in first pass.

## Proposed Package

```text
packages/pbb-hotline-alert-sdk/
```

Suggested package shape:

```text
packages/pbb-hotline-alert-sdk/
  src/
    HotlineAlertClient.js
    normalizeAlertStatus.js
  demo/
    alert-sdk.html
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

## REST Bootstrap

Proposed endpoint:

```text
GET /api/public/alert-status
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
  }
}
```

The endpoint should be public/read-only and safe for sibling PBB apps to call. It must not expose secrets, user data, operational records, SITREP payloads, support requests, or incident data.

## Realtime Event

Proposed event type:

```text
hotline.alert.changed
```

Suggested room:

```text
hotline.settings.global
```

Payload:

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
    },
    "presentation": {
      "voice": "default",
      "audio_graph_style": "tsunami"
    }
  }
}
```

If Hotline already emits a settings/broadcast event for alert changes, the SDK can adapt to the existing event as long as the public SDK event remains stable.

## SDK API Sketch

```js
import { HotlineAlertClient } from './src/HotlineAlertClient.js';

const client = new HotlineAlertClient({
  hotlineBaseUrl: 'https://hotline.pbb.ph',
  realtimeClient,
});

await client.bootstrap();

client.on('alert.changed', (alert) => {
  console.log(alert.level);
});
```

Constructor options:

```js
{
  hotlineBaseUrl,
  realtimeClient,
  room,
  fetchImpl,
  logger,
  autoSubscribe
}
```

Events:

```text
alert.loaded
alert.changed
alert.error
alert.reconnected
```

Methods:

```js
bootstrap()
subscribe()
unsubscribe()
current()
on(eventName, handler)
off(eventName, handler)
isNormal()
isElevated()
isCritical()
severityRank()
cssClass()
```

The SDK may expose helper functions, but they must be pure and framework-agnostic.

## Consumer Behavior Examples

Support:

- highlight urgent support requests when Hotline is critical;
- show current hub alert in support strategy views;
- filter/priority-sort incoming support work.

Utility/Vena:

- change map layer emphasis during elevated/critical periods;
- mark utility missions created while Hotline is elevated/critical;
- show hub alert status beside live Hotline incidents.

Landing:

- show public app shortcuts with alert-aware badges;
- avoid exposing operational details.

MapServer:

- optionally style hub boundary overlays based on alert level when supplied by a consuming app.

## Security And Privacy

Alert status is a hub/community posture signal, not a user secret. The public endpoint should still be minimal:

- no user/session data;
- no incident counts unless explicitly approved later;
- no support request data;
- no SITREP payload;
- no tokens;
- no raw settings dump.

The SDK should not require browser credentials for first-pass read access. If a future deployment needs private alert status, a separate authenticated endpoint can be added without breaking the public SDK object shape.

## Failure Behavior

On REST failure:

- SDK emits `alert.error`;
- `current()` returns the last known alert if available;
- if there is no last known alert, SDK may return a neutral `unknown` state.

On Realtime disconnect:

- SDK keeps last known alert;
- emits reconnect/error events if the underlying realtime client exposes them;
- does not repeatedly fetch in a tight loop.

## Versioning

Initial SDK version:

```text
0.1.0
```

Backward-compatible additions:

- new optional fields;
- new helper methods;
- support for additional Realtime event aliases.

Breaking changes:

- removing `level`, `label`, `severity`, or `updated_at`;
- changing allowed level semantics;
- making read access require authentication without a compatibility path.

## Open Questions

- Should the first endpoint be `/api/public/alert-status` or another namespace aligned with Landing public gateways?
- Should alert status include hub identity from Relay `/hub.json`, or only app identity in first pass?
- Should the SDK live as plain ES module only, or also ship a bundled browser file?
- Should Helper later provide a shared visual alert badge/clock component that consumes this SDK?
