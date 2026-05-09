# PBB Helper Proposal: Signal Strength Component

## Project
PBB Hotline Beta

## Date
2026-05-06

## Summary
Hotline Beta needs a reusable signal-strength UI component that can show frontend connectivity quality to a backing realtime service. The transport data now belongs to Realtime through the newly accepted client-health V1 contract, but the visual component should belong to Helper so other PBB browser apps can reuse the same compact connectivity indicator.

This proposal is intentionally visual/UI-only. Hotline should keep the app-specific adapter logic that maps Realtime health, browser online state, room readiness, and app-owned reconnect state into the component's props.

## Current Downstream Situation
Hotline Beta currently uses the shared Realtime service for caller, operator, and command browser surfaces.

Realtime has accepted and implemented a generic client-health V1 contract:

- authenticated `session.health.request`
- sender-only ack with health metadata
- SDK helper `RealtimeSocketClient.measureLatency(...)`
- SDK helper `getConnectionState()`
- normalized `state`, `health`, and `latency` events

Hotline can now derive a connectivity snapshot from:

- websocket/auth state
- measured RTT
- last successful health response age
- browser online/offline state
- room join readiness
- app-owned reconnect attempts and timers

The remaining gap is presentation. Hotline can create a local set of bars/pill UI, but that would likely become a copied pattern across PBB apps as HQ, Workspace, Maestro, Relay, and other browser surfaces adopt the same Realtime health contract.

## Problem
Without a shared Helper component:

- each app will design its own signal bars, colors, labels, and accessibility behavior
- compact nav/header placement will vary across apps
- small-screen behavior will be re-solved repeatedly
- "0-4 bars" semantics may drift visually even when apps use the same Realtime health data
- Hotline would need to keep UI CSS for what should be a shared status primitive

This is a good fit for Helper because the component is a generic status indicator, not a Hotline-specific workflow.

## Proposed Shared Contract
Add a Helper UI component for compact signal strength display.

Suggested API name:

```js
const signal = createSignalStrength(container, {
  label: "Realtime",
  level: 0,
  tone: "offline",
  text: "Offline",
});
```

The component should return the usual Helper-style instance:

```js
signal.update({
  level: 4,
  tone: "ok",
  text: "84 ms",
  title: "Realtime connected (84 ms)",
});

signal.destroy();
```

## Suggested Options

```js
{
  label: "Realtime",
  level: 0,              // 0-4
  tone: "offline",       // ok | warn | danger | offline | neutral
  text: "Offline",
  title: "",
  ariaLabel: "",
  showText: true,
  size: "compact",       // compact | regular
}
```

### Required Fields

- `level`: numeric 0 through 4
- `tone`: visual tone
- `text`: short visible status text

### Optional Fields

- `label`: stable status domain, for example `Realtime`
- `title`: tooltip text
- `ariaLabel`: accessible status text
- `showText`: allow icon/bars-only rendering in tight spaces
- `size`: compact or regular layout

## Expected Rendering

The component should render:

- 0-4 ascending signal bars
- short text label beside the bars when `showText !== false`
- tone-aware border/text/bar color
- stable dimensions so the navbar/header does not shift when text changes from `Offline` to `84 ms`
- accessible `role="status"` or equivalent live-region behavior
- useful tooltip/title text for hover/focus

Recommended tone mapping:

- `ok`: healthy, high signal
- `warn`: reconnecting, degraded, stale, or medium signal
- `danger`: weak signal or repeated health failures
- `offline`: disconnected
- `neutral`: unknown/idle

## Non-Goals

Helper should not:

- call Realtime directly
- know about `RealtimeSocketClient`
- run latency probes
- own reconnect logic
- know about Hotline rooms or business state
- decide what RTT threshold equals 1, 2, 3, or 4 bars

Those mappings should remain in each app's adapter layer.

## Hotline Adapter Direction

Hotline will keep a small app-owned adapter that consumes Realtime/browser facts and calls `signal.update(...)`.

Example mapping owned by Hotline:

```js
signal.update({
  level: 4,
  tone: "ok",
  text: "84 ms",
  title: "Realtime connected (84 ms)",
  ariaLabel: "Realtime connected, 84 milliseconds",
});
```

When Realtime is unavailable or the SDK is older:

```js
signal.update({
  level: 0,
  tone: "offline",
  text: "Offline",
});
```

When the app reconnect loop is active:

```js
signal.update({
  level: 1,
  tone: "warn",
  text: "Reconnecting",
});
```

## Suggested File Placement

If accepted, Helper could place the component near other generic UI primitives:

- `js/ui/ui.signal.strength.js`
- `css/ui/ui.signal.strength.css`
- registry loader entry such as `ui.signal.strength`

The exact naming is Helper-owned.

## Acceptance Criteria

- Helper exposes a reusable signal-strength component with `update(...)` and `destroy()`.
- The component supports `level` values 0 through 4.
- The component supports at least `ok`, `warn`, `danger`, `offline`, and `neutral` tones.
- Text changes do not cause layout jitter in compact navbar/header usage.
- The component is accessible by default with a sensible status label.
- It can render as bars-only when `showText: false`.
- Demo coverage shows offline, reconnecting, weak, medium, and strong states.
- Regression coverage verifies level/tone updates, text updates, bars-only mode, and destroy cleanup.

## Open Questions

- Preferred Helper registry name: `ui.signal.strength`, `ui.status.signal`, or another naming pattern?
- Should the component support horizontal bars only, or also a dot/pill variant for dense grids?
- Should Helper include a small adapter helper for common level/tone normalization, or keep normalization entirely app-owned?
