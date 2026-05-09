# PBB Helper Proposal: Media Strip Processing Placeholders

## Project
PBB Hotline Beta

## Date
2026-04-19

## Summary
Hotline Beta needs additive pending-media support in the shared Helper `createMediaStrip(...)` component so media items can appear in the strip while they are still being processed, then resolve in place when the final media source becomes available.

This is a shared UI concern, not a Beta-only rendering concern, because both caller and operator surfaces use the same Helper media strip and both should show the same processing state behavior.

## Current Downstream Situation
Hotline Beta currently uses Realtime media lifecycle events:

- `media.processing`
- `media.available`

App-owned behavior is already aligned with that contract:

- when `media.processing` arrives, Beta merges the media item into in-memory incident media
- when `media.available` arrives, Beta updates that same media item by `media.id`

However, the current shared Helper media strip drops items that do not yet have a resolved playable/renderable source.

Current Helper behavior in `js/ui/ui.media.strip.js`:

- items are normalized through `normalizeItems(...)`
- non-image/video items are dropped
- items without `srcUrl` are dropped

That means `media.processing` items do not render in the strip at all, even though Beta already has a valid media identity and processing state in memory.

## Problem
Without shared pending-media support:

- the strip stays blank while processing is ongoing
- the user gets no visual indication that media capture/upload already exists
- the eventual `media.available` update feels like delayed appearance instead of state transition
- downstream apps would need to fork local strip implementations or wrap the Helper strip with app-local placeholder UI

That would duplicate presentation logic that belongs in the shared component.

## Proposed Shared Contract
Add additive support in `createMediaStrip(...)` for pending media items.

### Suggested Item Shape
Allow items like:

```js
{
  id: 456,
  type: "video", // or "image"
  processing: true,
  srcUrl: "",
  thumbUrl: "",
  posterUrl: "",
  title: "Caller Video",
  alt: "Caller video",
  metadata: {
    processing: true,
    track_kind: "video",
  },
}
```

Important point:

- `id` is the stable identity key
- `processing: true` is the signal that the item should still render even without a resolved source URL

### Expected Behavior
When an item has `processing: true` and no usable `srcUrl` yet:

- render a placeholder thumbnail/card in the strip
- show a spinner or processing indicator
- show a short label such as `Processing...`
- do not attempt viewer open/playback
- preserve the item in `update(...)` state by `id`

When a later `update(...)` call provides the same `id` with real media URLs:

- replace that placeholder item in place with the real image/video thumb
- keep strip ordering stable
- keep viewer behavior normal for the resolved item

## Narrow Rendering Guidance
This proposal does **not** ask Helper to own media lifecycle or transport semantics.

Helper only needs to support rendering of an already-known pending item.

Hotline Beta remains responsible for:

- listening to `media.processing`
- listening to `media.available`
- merging media state by `media.id`
- calling strip `update(...)`

Helper remains responsible for:

- visual placeholder rendering
- stable keyed reconciliation in the strip
- swapping pending item UI to resolved item UI when the same item gains source URLs

## Recommended Minimal Rules
Suggested first-pass rules:

1. `processing: true` allows an image/video item to survive normalization even if `srcUrl` is empty.
2. Processing items render a non-clickable placeholder thumb.
3. Processing items should not open the viewer.
4. `update(...)` should reconcile by `id`, so the same item can transition from placeholder to real media without flicker or reorder.
5. Existing resolved-media behavior should remain unchanged.

## Why This Belongs In Helper
This is shared presentation behavior across any downstream app that uses:

- `createMediaStrip(...)`
- asynchronous media creation/finalization
- a state transition from pending asset to available asset

If this is not handled in Helper, each downstream app will need its own local pending-thumbnail implementation or wrapper around the shared strip.

## Beta Acceptance Target
Hotline Beta would consider this solved if the following works:

1. Beta passes a `processing: true` media item with stable `id` but no `srcUrl`.
2. The strip renders a visible placeholder card with spinner.
3. Clicking that placeholder does nothing.
4. Beta later calls `update(...)` with the same `id` and real `srcUrl`.
5. The placeholder card becomes the real media thumb in place.

## Local Beta Context
Current Beta callers/operators are already using shared Helper strips in:

- `resources/js/surfaces/callerSurface.js`
- `resources/js/surfaces/operatorSurface.js`

Current Beta Realtime media event model already uses:

- `media.processing`
- `media.available`

So this proposal is specifically about the shared strip rendering contract, not Beta-side transport design.
