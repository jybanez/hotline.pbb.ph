# Realtime Presence Metadata Proposal

## Owner
PBB Hotline Beta

## Target
PBB Realtime

## Summary
Add optional structured metadata support to Realtime presence so product apps can attach small machine-readable context to presence roster entries without overloading `status_text`.

Initial Hotline use case:
- operator presence in `presence.call.discovery`
- include active workbench context when relevant
- expose `incident_id` to caller-side consumers for display/debug/context only

## Problem
Current Realtime presence supports:
- `state`
- `status_text`
- `updated_at`

Current emitted roster payload contains:
- `subject`
- `state`
- `status_text`
- `updated_at`
- `expires_at`

This makes presence suitable for transport state, but not for small application context such as:
- active workbench incident id
- app-specific availability context
- other lightweight machine-readable hints

Today the only workaround is to encode data into `status_text`, which is brittle and mixes human-facing copy with machine data.

## Proposal
Allow `presence.publish` to accept an optional `meta` object and include that same `meta` object in `presence.state.event`.

Proposed publish payload:

```json
{
  "room": "presence.call.discovery",
  "state": "busy",
  "status_text": "busy",
  "meta": {
    "incident_id": 96,
    "workbench_active": true
  }
}
```

Proposed emitted event payload:

```json
{
  "subject": {
    "project_code": "prj_hotline_operator",
    "app_code": "hotline",
    "user_id": "2",
    "session_id": "..."
  },
  "state": "busy",
  "status_text": "busy",
  "meta": {
    "incident_id": 96,
    "workbench_active": true
  },
  "updated_at": "...",
  "expires_at": "..."
}
```

## Contract Notes
- `meta` should be optional.
- Existing clients should continue working unchanged when `meta` is absent.
- `meta` should be JSON-safe only.
- `meta` should be shallow and small.
- Presence metadata should be treated as UI context, not as authoritative business state.
- Realtime should not trust `meta` for permissions or workflow decisions.

## Suggested Constraints
- maximum depth: 1 object level
- allowed value types: string, number, boolean, null
- no arrays for V1 unless Realtime explicitly wants them
- reasonable encoded size cap, for example 512 to 1024 bytes

## Hotline Use
Hotline would publish:
- `state: online` when operator can answer discovery requests
- `state: busy` when operator is claimed, has incoming/connecting state, or has an active workbench call
- `meta.incident_id` when a workbench incident is active
- `meta.workbench_active` as a boolean convenience flag

Caller-side Hotline would only consume this for:
- availability context
- optional debug visibility
- optional future UI hints

Call claim, answer, hangup, and permission checks would remain unchanged and stay outside presence metadata.

## Implementation Areas In Realtime
Likely touchpoints:
- websocket presence publish handler
- presence payload builder
- SDK presence helper typings/documentation
- roster reducer helpers if typed payload shape is mirrored there

Likely files:
- `C:\wamp64\www\pbb\realtime\app\Realtime\WebSocket\RealtimeGateway.php`
- `C:\wamp64\www\pbb\realtime\resources\js\sdk\presence\realtime-presence.js`
- `C:\wamp64\www\pbb\realtime\resources\js\sdk\core\realtime-types.js`

## Backward Compatibility
- additive only
- no changes required for existing publishers
- no changes required for existing subscribers that ignore unknown payload fields

## Recommendation
Implement `meta` as the only new structured presence field for V1.

Avoid introducing multiple overlapping fields like `context`, `data`, and `meta`. One optional `meta` object is enough to support Hotline and future projects while keeping the contract narrow.
