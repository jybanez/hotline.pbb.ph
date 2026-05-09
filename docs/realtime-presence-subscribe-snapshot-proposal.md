# Realtime Presence Subscribe Snapshot Proposal

## Owner
PBB Hotline Beta

## Target
PBB Realtime

## Summary
Add an initial presence snapshot response for `presence.subscribe` so subscribers immediately receive the current roster for the room instead of waiting for a future delta event.

Initial Hotline use case:
- caller subscribes to `presence.global.hotline`
- caller needs current operator availability immediately after websocket join/subscribe
- caller should not remain yellow simply because no new operator presence change happened after subscribe

## Problem
Hotline currently treats operator discovery availability as presence-driven state in `presence.global.hotline`.

Current caller flow:
- caller joins the room
- caller sends `presence.subscribe`
- caller initializes availability to yellow
- caller only turns green after a later `presence.state.event` arrives

This creates a stale-start problem:
- if operators are already online before the caller page loads or refreshes
- and no operator presence delta happens after the caller subscribes
- caller stays yellow even though operators are actually available

That means subscriber correctness currently depends on a future unrelated publisher-side update.

## Why This Is A Realtime Contract Gap
The missing piece is not a caller-side rebroadcast request.

Having the caller ask operators to republish presence would be the wrong ownership boundary because:
- subscribers should not be responsible for rebuilding authoritative current room state
- presence providers should not need synthetic republish traffic just because a new subscriber arrived
- roster reconstruction should come from Realtime's current room state, not from downstream workaround choreography

## Proposal
When a client successfully subscribes to presence for a room, Realtime should immediately emit a snapshot event to that subscriber containing the room's current presence roster.

Suggested additive contract:
- request remains `presence.subscribe`
- existing ongoing delta event remains `presence.state.event`
- new seed event is emitted once after successful subscribe, for example `presence.snapshot.event`

Suggested event shape:

```json
{
  "type": "presence.snapshot.event",
  "room": "presence.global.hotline",
  "payload": {
    "entries": [
      {
        "subject": {
          "project_code": "prj_...",
          "app_code": "clt_...",
          "user_id": "17",
          "session_id": "..."
        },
        "state": "online",
        "status_text": "available",
        "meta": {
          "incident_id": 102,
          "workbench_active": true
        },
        "updated_at": "2026-04-23T02:20:00+08:00",
        "expires_at": "2026-04-23T02:20:30+08:00"
      }
    ]
  }
}
```

## Contract Notes
- additive only
- existing subscribers continue working if they ignore the new snapshot event
- snapshot should be subscriber-scoped, not broadcast to the room
- snapshot should represent the room roster as Realtime currently knows it at subscribe time
- later `presence.state.event` messages remain the incremental update stream
- payload shape should match the existing roster entry shape as closely as possible

## Delivery Semantics
Recommended semantics:
- client sends `presence.subscribe`
- Realtime acknowledges the subscribe request as it does today
- Realtime emits one `presence.snapshot.event` to the subscribing client
- subsequent presence changes continue through normal `presence.state.event` events

If Realtime already has an internal room roster reducer, this event should simply expose that current roster to the new subscriber.

## Hotline-Side Expected Behavior
With this contract, Hotline caller flow becomes:
- join `presence.global.hotline`
- subscribe to presence
- receive `presence.snapshot.event`
- derive immediate availability from snapshot entries
- continue applying later `presence.state.event` updates as operators change state

This removes the stale yellow-on-refresh case without introducing subscriber-triggered republish traffic.

## Compatibility With Existing Presence Metadata
This proposal is compatible with the already discussed additive `meta` support.

If `meta` is present on roster entries, the snapshot should include it in the same way that `presence.state.event` does.

## Recommendation
Implement a narrow first pass:
- add `presence.snapshot.event` emitted only to the subscribing client
- keep the entry shape aligned with current presence roster/state payloads
- keep `presence.state.event` unchanged for ongoing updates

This is the cleanest way to make presence subscriptions immediately correct for downstream apps like Hotline.
