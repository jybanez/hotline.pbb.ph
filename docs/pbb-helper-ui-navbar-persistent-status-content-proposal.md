# PBB Helper Proposal: Navbar Persistent Status Content

## Project
PBB Hotline Beta

## Date
2026-05-06

## Summary
Hotline Beta needs a Helper-owned navbar region for compact status indicators that remain visible across viewport sizes while preserving the current mobile-collapse behavior for normal navigation items and actions.

The proposed region sits before `actions[]` on large screens and before the hamburger menu button on small screens. It is intended for per-surface and per-user status content such as signal strength, device-primer state, broadcast/announcement readiness, active call state, and other top-level operational indicators.

## Current Downstream Situation
`createNavbar(...)` already supports:

- `items[]`
- `actions[]`
- `contentStart`
- `contentCenter`
- `contentEnd`
- `contentStartMobile`
- `contentCenterMobile`
- `contentEndMobile`
- `mobileCollapse`, defaulting to `true`

On narrow screens, the existing mobile-collapse path keeps the brand visible and moves the rest into the hamburger menu. When explicit `content*Mobile` entries are omitted, the mobile menu falls back to text content from the desktop content slots, followed by `items[]`, then `actions[]`.

That behavior is useful and should remain the default. The gap is that some status content is not navigation and should not disappear into the hamburger menu.

## Problem
Some Hotline users need always-visible operational indicators in the navbar, but the visible indicator set differs by user type and surface.

Examples:

- Caller surface:
  - signal strength or realtime latency
  - device-primer warning
  - broadcast/announcement audio readiness
  - active call state
- Operator surface:
  - queue or assigned-call indicator
  - realtime connection quality
  - audio device readiness
  - unread broadcast indicator
- Command surface:
  - broadcast delivery state
  - realtime connection quality
  - incident monitoring state

These indicators are not app navigation, so placing them in `items[]` is semantically wrong. They are also not ordinary actions, so placing them in `actions[]` causes them to collapse into the hamburger menu on mobile. App-local positioning outside the navbar works, but it creates a second header row and misses the available inline space beside the burger button.

## Proposed Shared Contract
Add an optional persistent navbar content slot.

Suggested option name:

```js
createNavbar(container, data, {
  brandText: "PBB Hotline Beta",
  statusContent: [
    signalIndicator,
    broadcastIndicator,
    devicePrimerIndicator,
  ],
  actions: [
    installAction,
  ],
});
```

Rendering order:

```text
Desktop: brand/title -> items/content -> statusContent -> actions
Mobile:  brand/title -> statusContent -> hamburger
```

`statusContent` must not be included in the mobile hamburger menu. It is persistent inline content.

## Suggested Option Shape

```js
{
  statusContent: null,
  statusContentMobile: null,
  statusContentLabel: "Status",
  maxStatusItems: null,
}
```

### `statusContent`
Persistent content rendered inline before `actions[]` on desktop and before the hamburger toggle on mobile.

Recommended accepted forms should match existing Helper content-slot conventions where practical:

- `Node`
- `string`
- `function(ctx) => Node | string | Array<Node | string>`
- `Array<Node | string | function>`

### `statusContentMobile`
Optional override for narrow screens. When omitted, mobile uses `statusContent`.

This lets an app render richer desktop status content and a tighter mobile version without changing the status semantics.

### `statusContentLabel`
Accessible group label for assistive technology. Default can be `"Status"`.

### `maxStatusItems`
Optional overflow guard for dense mobile usage. If Helper supports this, excess status entries should move into a helper-owned overflow menu within the persistent status region, not into the main hamburger navigation menu.

This field is optional for V1. A first implementation can omit it if status content remains app-sized.

## Per-User / Per-Surface Ownership
Helper should not decide which indicators a user sees.

The consuming app should build the `statusContent` array from its own surface, permissions, user role, feature flags, and runtime state, then pass the resulting content to Helper.

Example:

```js
const statusContent = [
  userCanSeeRealtimeHealth ? signalIndicator : null,
  surface === "caller" && needsDevicePrimer ? devicePrimerIndicator : null,
  userCanReceiveBroadcasts ? broadcastIndicator : null,
].filter(Boolean);

createNavbar(navEl, null, {
  brandText: "PBB Hotline Beta",
  statusContent,
  actions,
});
```

This keeps Helper generic while allowing Hotline caller, operator, command, and future PBB surfaces to show different status sets.

## Expected Behavior

- `mobileCollapse: true` continues to collapse normal `items[]`, `actions[]`, and existing content slots into the hamburger menu.
- `statusContent` remains inline and visible on mobile.
- On desktop, `statusContent` appears before `actions[]`.
- On mobile, `statusContent` appears immediately before the hamburger button.
- If there are no mobile menu items, `statusContent` should still render inline and should not create an empty hamburger menu.
- If `statusContent` is empty, navbar behavior remains unchanged.
- Updating the navbar with new status content should clean up prior status DOM and event listeners.
- The status region should use stable dimensions and wrapping/overflow rules so short status changes do not cause navbar jitter.

## Non-Goals

Helper should not:

- decide which status indicators a role or user receives
- know about Hotline caller/operator/command roles
- connect to Realtime directly
- run latency probes
- own broadcast, call, device-primer, or session state
- force all status content into one visual component
- replace `items[]`, `actions[]`, or existing `content*` slots

## Helper Implementation Direction

Suggested file scope:

- `js/ui/ui.navbar.js`
- `css/ui/ui.nav.css`
- `README.md`
- navbar demo coverage, likely `demos/demo.nav.html`

Implementation should remain additive:

- preserve the existing `createNavbar(container, data, options)` signature
- preserve the existing returned API shape
- keep `mobileCollapse` behavior unchanged for existing options
- add only optional configuration
- avoid coupling this slot to Hotline-specific classes

Suggested DOM shape:

```html
<div class="ui-navbar-status" role="group" aria-label="Status">
  ...
</div>
```

Suggested placement:

- append to the navbar end cluster before `.ui-navbar-actions` on desktop
- keep visible under `.is-mobile-active`
- place before `.ui-navbar-mobile-toggle` in DOM order so it naturally sits before the burger button

## Hotline Adapter Direction

Hotline should build status content from app-owned adapters:

- signal strength component from realtime/browser health
- device-primer status from caller audio/speech readiness
- broadcast indicator from announcement subscription/audio state
- active-call indicator from caller/operator call state

Each surface should decide which indicators matter:

- Caller should prioritize emergency readiness and audio/broadcast readiness.
- Operator should prioritize call-handling and queue state.
- Command should prioritize broadcast and monitoring state.

The same Helper navbar option can support all of these without Helper knowing the role model.

## Acceptance Criteria

- Helper exposes an optional persistent navbar status/content slot.
- Existing navbar callers behave unchanged when the option is omitted.
- Existing mobile-collapse behavior remains unchanged for `items[]`, `actions[]`, and existing `content*` slots.
- Persistent status content renders before actions on desktop.
- Persistent status content renders before the hamburger button on mobile.
- Persistent status content is not duplicated inside the hamburger menu.
- Apps can provide different status content per surface/user without Helper role-specific logic.
- Status content can be updated without stale DOM or listener leaks.
- Demo coverage shows:
  - desktop navbar with status content before actions
  - mobile navbar with status content before burger
  - caller-like status set
  - operator-like status set
  - empty status content fallback
- README documents the new option and explicitly distinguishes it from `contentStartMobile`, `contentCenterMobile`, and `contentEndMobile`.

## Open Questions

- Preferred option name: `statusContent`, `persistentContent`, `fixedContent`, or `inlineStatusContent`?
- Should V1 include `statusContentMobile`, or should callers handle compact rendering inside `statusContent` using CSS?
- Should Helper provide a built-in overflow affordance for too many status indicators, or should consuming apps keep the status set intentionally small?
- Should the status region expose a named CSS width token or rely on content sizing only?
