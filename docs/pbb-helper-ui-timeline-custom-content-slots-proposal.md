# PBB Helper UI Timeline Custom Content Slots Proposal

## Context

Hotline Beta needs to show richer incident timeline entries than the current `ui.timeline` item model supports. The immediate case is a call-session timeline entry that should host an `AudioCallSession` component for caller/operator audio playback, but the need is broader: timeline entries should be able to contain lifecycle-managed custom content from the host app or other Helper components.

The current Helper timeline API supports fixed fields such as `title`, `subtitle`, `description`, `timestamp`, `status`, `meta`, `actions`, and `iconHtml`. It does not provide an official content slot or mount lifecycle. In addition, `createTimeline(...).update(...)` clears the timeline container and rebuilds DOM, so mounting custom DOM into `.ui-timeline-body` from Hotline would be fragile and would lose component state/listeners on every timeline update.

## Problem

Hotline can technically find a rendered timeline item and append app-owned DOM, but that creates several risks:

- Timeline updates can destroy nested components without calling their cleanup logic.
- Audio players, event listeners, timers, and media state can leak or reset unexpectedly.
- Helper owns the timeline DOM structure, so downstream DOM targeting is brittle.
- The solution would only work for Hotline instead of becoming a reusable Helper capability.

## Proposal

Add a backward-compatible item-level custom content slot to `ui.timeline`.

Recommended V1 API:

```js
const timeline = PbbHelpers.ui.timeline.createTimeline(container, items, {
  mountItemContent(host, item, context) {
    if (item.type !== "call_session") {
      return null;
    }

    const audioSession = PbbHelpers.ui.audioCallSession.createAudioCallSession(host, {
      callerAudio: item.callerAudio,
      operatorAudio: item.operatorAudio,
    });

    return () => audioSession.destroy?.();
  },
});
```

Helper would create a stable content host inside each item body, for example:

```html
<div class="ui-timeline-custom-content"></div>
```

If `mountItemContent` returns a function, Helper treats it as the disposer. If it returns an object with `update(nextItem, context)` and/or `destroy()`, Helper can call `update` when the same timeline item remains present across `timeline.update(...)`, and `destroy` when the item is removed or the whole timeline is destroyed.

## Suggested Contract

- `mountItemContent(host, item, context)` is optional and defaults to no-op.
- The existing timeline rendering remains unchanged when the option is absent.
- Helper calls the hook after rendering the fixed timeline body fields.
- `host` is an empty element owned by Helper and intended only for custom content.
- `context` should include at least `{ index, total, timeline, options }`.
- Helper tracks mounted content by stable item `id`.
- Helper calls cleanup before removing an item, remounting changed content, or destroying the timeline.
- Helper should not use raw untrusted HTML as the primary extension mechanism.

Optional useful additions:

- `item.contentKey` to force remount when app-specific content identity changes.
- `item.hasCustomContent` or similar to avoid rendering empty slot hosts when the app knows no content is needed.
- A CSS hook such as `.ui-timeline-custom-content` with neutral spacing that works in vertical, horizontal, compact, and comfortable modes.

## Hotline Use Case

Hotline would represent call session timeline rows as regular timeline items, then mount an `AudioCallSession` inside the content slot. Media availability events can update the timeline item data and then call `timeline.update(...)`. If the item remains the same call session, Helper can update or preserve the nested audio component instead of requiring Hotline to rebuild the timeline manually.

This should also allow future content such as:

- Inline media previews.
- Assignment change summaries with structured controls.
- Incident status panels.
- Embedded Helper widgets.
- App-owned custom forms or review cards.

## Acceptance Criteria

- Existing timeline consumers and demos continue working without code changes.
- Timeline still supports grouped, vertical, horizontal, compact, and comfortable rendering.
- Custom content cleanup runs on `update`, item removal, and `destroy`.
- Nested components do not leak event listeners or timers across timeline updates.
- Host apps do not need to query Helper-owned DOM by class names to mount content.
- Click and keyboard behavior for timeline item selection and item actions remain unchanged.
- A demo shows at least one custom content item and verifies that update/destroy cleanup is called.

## Out Of Scope

- Moving `AudioCallSession` ownership into `ui.timeline`.
- Making timeline a generic layout or form-builder component.
- Supporting arbitrary unsafe HTML injection as the main extension path.
- Changing current fixed timeline item fields or existing item normalization behavior.

## Suggested Helper Files To Review

- `public/vendor/helpers.pbb.ph/js/ui/ui.timeline.js`
- `public/vendor/helpers.pbb.ph/demos/demo.timeline.html`
- `public/vendor/helpers.pbb.ph/js/ui/ui.loader.js`

## Recommendation

Implement this as an additive Helper feature first, then Hotline can consume the official slot for call-session audio playback and any later custom timeline content without downstream DOM patching.
