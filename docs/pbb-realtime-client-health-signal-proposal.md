# PBB Realtime Client Health Signal Proposal

## Context

Hotline Beta needs a reusable frontend signal-strength UI that reflects the live connectivity quality between a browser surface and the shared Realtime service.

The current Hotline integration already uses Realtime admission and `RealtimeSocketClient` on caller, operator, and command surfaces. The available client hooks are enough to show coarse lifecycle state:

- websocket opened
- websocket closed
- websocket error
- app-level room join acknowledgements
- app-owned reconnect attempts

That is enough for a simple "connected / reconnecting / offline" indicator, but it is not enough for an honest signal-strength UI. A signal-strength component needs a stable way to measure live round-trip latency, know whether the Realtime session is authenticated, and distinguish a weak but connected path from a fully offline path.

Hotline can build a local approximation from `onOpen`, `onClose`, and reconnect state, but that would duplicate transport interpretation in every product app and would not produce a reusable shared quality signal.

## Goals

- Add a small, generic Realtime health contract that product apps can use for signal-strength and connection-quality UI.
- Keep the contract Realtime-owned and app-agnostic.
- Avoid coupling health checks to business rooms, Hotline events, chat messages, media ingest, or presence rosters.
- Expose enough SDK state for product apps to render consistent connectivity UI without reverse-engineering websocket internals.
- Preserve all current Realtime admission, room join, event publish, chat, media, and presence contracts.

## Non-Goals

- Realtime does not need to own or render the Hotline signal-strength UI.
- Realtime does not need to classify every product-specific degraded state.
- This proposal does not require changing existing request envelope shapes.
- This proposal does not require server-side room fanout for health probes.
- This proposal does not require Realtime to become a general network diagnostics service.

## Proposed Contract

Add a sender-scoped websocket health request/response pair.

### Health Request

Browser sends a normal Realtime request over the existing authenticated websocket:

```json
{
  "type": "session.health.request",
  "room": null,
  "payload": {
    "client_time": "2026-05-06T04:00:00.000Z"
  }
}
```

The request should be allowed for any authenticated session with `session.connect`. It should not require a joined room.

### Health Response

Realtime replies only to the sender:

```json
{
  "phase": "ack",
  "type": "session.health.request",
  "payload": {
    "ok": true,
    "server_time": "2026-05-06T04:00:00.085Z",
    "connection_id": "optional-stable-connection-id",
    "session_id": "optional-realtime-session-id",
    "authenticated": true,
    "rooms_joined_count": 2,
    "gateway_uptime_seconds": 3600
  }
}
```

Only `ok`, `server_time`, and `authenticated` need to be required in V1. The other fields are useful diagnostics if they already fit Realtime's internal model.

The browser can calculate RTT using local timestamps around the request and response. The response does not need to trust or use the browser-provided `client_time`.

## SDK Additions

Add small helpers to `RealtimeSocketClient`.

### `measureLatency(options = {})`

Returns a promise:

```js
const health = await client.measureLatency();
```

Example result:

```js
{
    ok: true,
    rtt_ms: 84,
    measured_at: "2026-05-06T04:00:00.100Z",
    server_time: "2026-05-06T04:00:00.085Z",
    authenticated: true,
    rooms_joined_count: 2
}
```

Suggested behavior:

- return `ok: false` if the socket is not open
- reject or return `ok: false` on timeout
- use a short default timeout, for example 3000 ms
- avoid overlapping health probes unless the caller opts in

### `getConnectionState()`

Expose stable transport/session state without requiring product apps to inspect raw `WebSocket.readyState`.

Suggested states:

- `idle`
- `connecting`
- `open`
- `authenticated`
- `reconnecting`
- `closed`
- `error`

If Realtime prefers not to own reconnect state because product apps currently schedule reconnects themselves, V1 can expose only socket/session state and leave reconnect state to downstream apps.

### Event Hooks

The existing event emitter can remain the base API. Realtime can add normalized events without breaking current callbacks:

- `state`
- `health`
- `latency`

Example:

```js
client.on("latency", (snapshot) => {
    console.log(snapshot.rtt_ms);
});
```

## Hotline Signal Mapping

With the proposed contract, Hotline can implement one reusable module that maps health snapshots to bars:

- 4 bars: authenticated/open and RTT below 150 ms
- 3 bars: authenticated/open and RTT 150-400 ms
- 2 bars: authenticated/open and RTT 400-1000 ms
- 1 bar: authenticated/open but RTT above 1000 ms, health timeout recently, or repeated reconnect attempts
- 0 bars: socket closed, unauthenticated, offline, or no successful health response inside the stale window

This mapping can stay owned by Hotline. Realtime only needs to provide the transport facts.

## Degraded-State Inputs

The Hotline component can combine Realtime facts with browser facts:

- `navigator.onLine`
- websocket lifecycle
- Realtime authenticated state
- room join readiness
- latest RTT
- latest successful health response age
- current reconnect attempt count from the app surface

Realtime does not need to calculate the final product-app signal level.

## Compatibility

This should be fully additive:

- Existing clients that do not send `session.health.request` continue to work unchanged.
- Existing request and event types continue to work unchanged.
- Product apps can adopt the SDK helper gradually.
- Hotline can fall back to lifecycle-only signal state if connected to an older Realtime deployment.

## Acceptance Criteria

- A connected browser client can send `session.health.request` after authentication and receive a sender-only ack.
- The response does not require joining an app room.
- The JS SDK exposes a simple latency helper.
- The helper reports useful failure state when the websocket is closed or the health request times out.
- Focused Realtime tests cover accepted health request, unauthenticated rejection/ignore behavior, timeout behavior at SDK level, and compatibility with current request routing.
- Hotline can build a signal-strength UI without publishing app-specific events or joining a diagnostic room.

## Open Questions

- Should `session.health.request` be accepted before full token authentication, or only after authentication completes? Hotline's preference is authenticated-only.
- Should Realtime include `rooms_joined_count` or a list of joined room names? Hotline only needs the count for diagnostics; exposing room names is not necessary for V1.
- Should the SDK own reconnect state in the future, or should product apps keep their existing reconnect loops? Hotline can work either way, but a shared reconnect state would make future product integrations cleaner.
