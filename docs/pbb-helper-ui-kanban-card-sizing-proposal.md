# PBB Helper UI Kanban Card Sizing Proposal

## Context

Hotline Beta now uses Helper `ui.kanban` inside the operator dashboard map as a bottom floating assignment rail. The rail intentionally has enough vertical space to show about 2.5 assignment cards per lane while keeping the map visible behind it.

This exposed a layout issue in `ui.kanban`: cards stretch vertically to fill each lane instead of keeping their intrinsic card height and stacking from the top.

## Observed Behavior

When the kanban lane is taller than the natural height of its cards:

- A lane with one card stretches that card to consume most of the lane body.
- A lane with two cards stretches both cards vertically.
- Empty lanes stretch the empty placeholder region.
- The issue is most visible in horizontal rail layouts where lane height is controlled by the host surface.

In Hotline Beta this currently appears in the operator bottom assignment rail.

## Root Cause

Current Helper kanban CSS uses this structure:

```css
.ui-kanban-lane {
  display: grid;
  grid-template-rows: auto 1fr;
}

.ui-kanban-cards {
  display: grid;
  gap: 8px;
  min-height: 120px;
}
```

The lane header uses intrinsic height, while `.ui-kanban-cards` receives the remaining lane height via `1fr`. Since `.ui-kanban-cards` is itself a grid and does not explicitly opt out of grid row stretching, the card rows stretch inside the larger available area.

This is correct only if a host wants cards to fill the lane body. For most kanban use cases, cards should keep intrinsic height and align to the top, while the lane body provides scroll/drop space.

## Proposal

Add an explicit Helper-supported sizing/alignment contract for kanban card stacks.

Recommended default-safe CSS adjustment:

```css
.ui-kanban-cards {
  align-content: start;
  align-items: start;
}
```

This keeps cards at intrinsic height while still allowing the lane body to consume remaining height for drag/drop surface area.

If Helper wants to preserve the current stretch behavior for any existing consumers, add an additive option/class instead:

```js
createKanban(container, lanes, {
  cardSizing: "intrinsic", // default candidate
});
```

Potential values:

- `intrinsic`: cards stack at natural height from the top.
- `stretch`: current behavior, cards stretch to fill available lane body.

Possible classes:

```css
.ui-kanban--cards-intrinsic .ui-kanban-cards {
  align-content: start;
  align-items: start;
}

.ui-kanban--cards-stretch .ui-kanban-cards {
  align-content: stretch;
  align-items: stretch;
}
```

## Hotline Need

Hotline Beta needs `intrinsic` behavior for the operator bottom assignment rail:

- The rail height is fixed by dashboard layout.
- Lanes should use that height as viewport/drop area.
- Cards should remain compact and readable.
- Empty lanes should show a normal compact empty state, not a stretched panel.

Hotline can apply a local override temporarily, but this appears to be a general Helper layout contract rather than a Hotline-only styling issue.

## Acceptance Criteria

- Existing `ui.kanban` demos still render correctly.
- Cards do not stretch vertically when a lane is taller than card content under the intrinsic mode/default.
- One-card, two-card, many-card, and empty-lane cases are covered.
- Drag/drop target behavior still works across the full lane body.
- Keyboard movement behavior remains unchanged.
- A demo shows a fixed-height kanban board where cards stack naturally at the top.
- If `stretch` is retained, it is opt-in or documented clearly.

## Suggested Helper Files To Review

- `public/vendor/helpers.pbb.ph/js/ui/ui.kanban.js`
- `public/vendor/helpers.pbb.ph/css/ui/ui.kanban.css`
- `public/vendor/helpers.pbb.ph/demos/demo.kanban.html`

## Recommendation

Treat this as a small Helper-side kanban layout hardening task. The narrowest likely fix is `align-content: start` and `align-items: start` on `.ui-kanban-cards`, with regression/demo coverage for fixed-height lanes. If there is concern about changing defaults, expose it as a `cardSizing` option and let Hotline opt into `intrinsic`.
