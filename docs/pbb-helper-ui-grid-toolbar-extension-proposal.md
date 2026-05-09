# PBB Helper UI Grid Toolbar Extension Proposal

## Summary

`ui.grid` needs a supported toolbar-extension contract so PBB apps can place app-owned actions beside the built-in search, page-size, and future toolbar controls without relying on DOM reinjection after every grid redraw.

This proposal is based on a real downstream integration in `PBB Hotline Beta`, where the Admin Users page needs:

- the grid's own search UI
- an app-owned `Add User` button
- an app-owned record-count pill

The current helper grid can render the built-in search toolbar, but it does not expose a stable way to add app-owned toolbar content. Because the toolbar is recreated on internal render cycles, app code that manually appends buttons into the toolbar loses them whenever the grid re-renders.

## Current Gap

Current `ui.grid` behavior:

- owns its toolbar DOM internally
- rebuilds the toolbar during internal render/query updates
- exposes no stable toolbar slot or declarative toolbar-action option

Practical downstream effect:

- apps can enable built-in search
- but apps cannot safely add stable toolbar actions like `Add User`
- ad hoc DOM injection works only until the next grid redraw
- downstream apps end up using mutation observers or repeated reinjection, which is a helper-contract smell

## Current Hotline Workaround

Current Hotline implementation in `c:\wamp64\www\pbb\hotline`:

- `PBB Hotline Beta` uses `ui.grid` on the Admin Users page
- the page needs built-in grid search plus a right-side `Add User` action
- Hotline currently reinjects the record count and `Add User` button into `.ui-grid-tools-right`
- Hotline uses a `MutationObserver` because the toolbar is recreated during search-driven renders

This works, but it is not the right shared contract.

## Proposed Direction

Add a narrow, declarative toolbar extension contract to `ui.grid`.

Recommended shape:

```js
createGrid(container, rows, {
  columns,
  enableSearch: true,
  toolbarStart: null,
  toolbarEnd: null,
});
```

Where:

- `toolbarStart` renders after helper-owned left tools
- `toolbarEnd` renders after helper-owned right tools
- each option may accept:
  - an `HTMLElement`
  - a string
  - an array of nodes/items
  - or a function returning one of those

## Safer V1 Alternative

If the helper team wants a tighter first stage, a simpler additive contract is enough:

```js
createGrid(container, rows, {
  columns,
  enableSearch: true,
  extraToolbarActions: [
    {
      id: "add-user",
      placement: "end",
      label: "Add User",
      onClick() {},
    },
  ],
  extraToolbarContent: [
    {
      id: "record-count",
      placement: "end",
      render() {
        return myCountPill;
      },
    },
  ],
});
```

This is more constrained than raw slots, but still solves the current gap.

## Recommendation

Preferred V1:

- `toolbarStart`
- `toolbarEnd`

Reason:

- simpler and more flexible than inventing a second mini action schema
- consistent with how apps already think about toolbar composition
- avoids pushing every toolbar use case into only `button`-shaped actions
- supports mixed content such as:
  - search
  - count pills
  - add buttons
  - filters
  - export/import controls

## Behavioral Requirements

Any accepted toolbar-extension contract should guarantee:

- custom toolbar content survives grid re-renders
- grid-owned search/sort/page changes do not wipe app-owned toolbar content
- teardown is handled by grid `destroy()`
- toolbar additions do not require mutation observers in downstream apps

## Non-Goals

This proposal does not ask `ui.grid` to:

- own app business actions
- own Add/Edit/Delete semantics
- become a page-level header manager
- replace app-specific form/modal logic

It only asks `ui.grid` to expose a stable toolbar extension seam.

## Downstream Example

Concrete Hotline Beta use case:

- left: grid-owned search input
- right: `3 records` pill
- right: `Add User` button

Desired downstream code shape:

```js
const addButton = document.createElement("button");
addButton.className = "ui-button ui-button-primary";
addButton.type = "button";
addButton.textContent = "Add User";
addButton.addEventListener("click", () => openUserForm());

const countPill = document.createElement("span");
countPill.className = "pill blue";
countPill.textContent = `${rows.length} records`;

createGrid(host, rows, {
  columns,
  enableSearch: true,
  enableColumnResize: true,
  toolbarEnd: [countPill, addButton],
});
```

## Suggested Helper Follow-Through

If accepted, Helper should add:

- `docs/ui-grid-toolbar-extension-proposal.md` or equivalent response memo/spec
- `demos/demo.grid.html` coverage for toolbar extension
- regression coverage proving custom toolbar content survives search/sort/pagination re-renders
- README/playbook note so downstream apps stop using ad hoc toolbar DOM injection

## Recommendation To Helper

Treat this as a real shared gap, not a Hotline-only styling request.

The repeated workaround pattern is:

- app enables built-in grid search
- app needs one or two app-owned toolbar controls
- helper exposes no supported seam
- app falls back to DOM patching

That is exactly the kind of repeated downstream pressure the Helper playbook says should become a proposal rather than a local fork.
