# PBB Realtime App-Owned Event Ingress Proposal

Date: 2026-04-06

Status: Draft proposal from PBB Hotline Beta

## Purpose

Define a narrow Realtime-side ingress for product backends that need to publish trusted app-owned events into existing Realtime rooms without turning Realtime into a business-orchestration layer.

Immediate Hotline Beta use case:
- live settings propagation
- especially `alert_level` updates after admin settings save

## Current Gap

PBB Realtime currently supports:
- backend-issued session admission
- websocket auth
- room join/leave
- presence fanout
- chat fanout
- call signaling fanout
- sandbox attachment chunk fanout

But it does not yet expose a product-backend ingress for app-owned events such as:
- `hotline.settings.updated`
- `hotline.alert_level.changed`
- `hotline.media.processing`
- similar business-owned event families that need live transport

That leaves downstream apps with a transport gap:
- they can mint trusted Realtime tokens
- they can connect browser clients
- but they cannot ask Realtime to fan out a trusted server-originated event to current room members

## Why Hotline Needs This

Hotline Beta runtime settings already include:
- Realtime client code
- Realtime URL
- Realtime token signing secret

Hotline can now issue real Realtime admissions correctly.

However, the Beta checklist still requires:
- live settings updates
- especially immediate `alert_level` propagation across public/caller/operator/admin surfaces

Polling would work as a fallback, but it is the wrong long-term path when Realtime already exists as the shared transport layer.

## Proposed Narrow Direction

Add a small product-backend ingress for app-owned room events.

Recommended shape:
- trusted backend-to-Realtime HTTP endpoint
- validated with a product-owned shared secret or equivalent trusted backend credential
- accepts:
  - `app_code`
  - `project_code`
  - `room`
  - `event_type`
  - `payload`
  - optional `meta`
- Realtime validates authorization and then broadcasts a server-originated `event` envelope to current room members

### Example endpoint

- `POST /api/v1/events/publish`

### Example payload

```json
{
  "app_code": "clt_01KMXFPRXCTHJAG10DMACJFMYB",
  "project_code": "prj_hotline_public",
  "room": "hotline.settings.global",
  "event_type": "hotline.alert_level.changed",
  "payload": {
    "alert_level": "Red",
    "changed_at": "2026-04-06T03:55:00Z"
  },
  "meta": {
    "source": "pbb-hotline-backend"
  }
}
```

## Scope Boundary

This proposal is intentionally narrow.

Realtime should still not own:
- business authorization decisions
- Hotline workflow rules
- incident lifecycle
- routing logic
- settings persistence

Hotline backend still decides:
- that an event should be emitted
- which room is correct
- what event type and payload are valid for Hotline

Realtime only validates ingress trust and transports the event.

## Recommended V1 Rules

- ingress is backend-only, never browser-issued
- room authorization still respects trusted app/project boundaries
- event publish should be allowed only to rooms that the publishing app/project is allowed to target
- Realtime should not reinterpret Hotline payloads beyond transport validation
- published events should use the existing websocket `event` envelope phase

## Suggested Hotline First Use

First room:
- `hotline.settings.global`

First event families:
- `hotline.settings.updated`
- `hotline.alert_level.changed`

First client behavior:
- apply `alert_level` immediately
- apply call timing / graph / voice changes on next safe action when current call state makes immediate mutation risky

## Why This Should Be Shared

This is not Hotline-only.

Other PBB products are likely to need:
- server-originated live notices
- system-state fanout
- worker/runtime broadcasts
- admin-triggered event fanout

So the ingress should live in Realtime as a shared transport contract, not as a Hotline-local websocket bypass.

