# PBB Helper UI Tree Select Proposal

## Summary

PBB apps need a supported hierarchical select control for cases where options are naturally grouped under parent categories and a flat searchable dropdown is technically usable but operationally noisy.

This proposal is based on a real downstream need in `PBB Hotline Beta`, where team resource inventory management needs to select a resource type from a category-organized catalog:

- `Medical`
  - `Ambulance`
  - `First Aid Kit`
  - `Medical Supplies`
- `Supply`
  - `Food & Water Supplies`
- `Tool`
  - `Hydraulic Cutter`
  - `Night Vision`
  - `Satellite Phone`

The current Helper `ui.select` can search across flat labels like `Medical / Ambulance`, but once the list grows, the flattened presentation makes scanning harder and weakens the benefit of the real category structure already present in app data.

## Current Gap

Current Helper behavior:

- `ui.select` supports searchable flat options
- app code can encode hierarchy into labels such as `Category / Item`
- the dropdown still renders as one flat list

Practical downstream effect:

- apps can technically select the correct record
- but category structure is not visually preserved
- scanning gets slower as catalogs grow
- duplicate-looking labels across categories become harder to disambiguate
- apps with true parent-child taxonomies lose an important part of the information architecture

## Current Hotline Use Case

Current Hotline implementation in `c:\wamp64\www\pbb\hotline`:

- Admin Teams -> Team Resources modal
- `Add Inventory` form uses a searchable `ui.select`
- options are flattened into labels like:
  - `Medical / Ambulance`
  - `Medical / First Aid Kit`
  - `Supply / Food & Water Supplies`

This works, but it is a compromise:

- the user must parse grouping from text instead of layout
- the more resource types Hotline imports from Alpha and future operations, the worse the flat list becomes

## Proposed Direction

Add a dedicated hierarchical select helper rather than forcing hierarchy into flat `ui.select` labels.

Recommended component name:

- `createTreeSelect(container, config)`

Recommended form-row integration:

- `type: 'ui.treeSelect'`

This should allow apps to preserve parent-child structure without inventing custom dropdown logic.

## Recommended API Shape

Direct helper:

```js
const treeSelect = createTreeSelect(host, {
  name: "resource_type_id",
  value: "2",
  multiple: false,
  searchable: true,
  clearable: false,
  placeholder: "Select resource type...",
  nodes: [
    {
      id: "medical",
      label: "Medical",
      children: [
        { id: "2", label: "Ambulance" },
        { id: "1", label: "First Aid Kit" },
        { id: "3", label: "Medical Supplies" },
      ],
    },
    {
      id: "supply",
      label: "Supply",
      children: [
        { id: "4", label: "Food & Water Supplies" },
      ],
    },
  ],
});
```

Form modal row:

```js
{
  type: "ui.treeSelect",
  name: "resource_type_id",
  label: "Resource Type",
  required: true,
  multiple: false,
  searchable: true,
  clearable: false,
  placeholder: "Select resource type...",
  nodes: resourceTypeNodes,
}
```

## Suggested Data Shape

Recommended tree node shape:

```js
{
  id: "medical",
  label: "Medical",
  disabled: false,
  selectable: false,
  meta: {},
  children: [
    {
      id: "2",
      label: "Ambulance",
      disabled: false,
      selectable: true,
      meta: {
        categoryName: "Medical",
      },
    },
  ],
}
```

Recommended semantics:

- parent category nodes are non-selectable by default
- leaf nodes are selectable by default
- apps may optionally mark parent nodes selectable if a use case really needs it

## Why A Dedicated Component Is Better Than Extending `ui.select`

Do not treat this as only a display tweak on `ui.select`.

Why a separate tree-select is cleaner:

- hierarchy is a first-class behavior, not just label formatting
- search behavior needs to preserve or reveal parent context
- keyboard navigation is different from a flat list
- expand/collapse state is a real concern
- future multi-select tree use cases become possible without bloating flat select behavior

If Helper prefers a lighter first step, it could still be implemented internally on top of the select foundations, but the public contract should be tree-aware.

## Required Behavior

Any accepted tree-select contract should support:

- parent-child option hierarchy
- searchable filtering
- visible parent context when searching for a child
- single-select mode
- optional multi-select mode
- keyboard navigation
- disabled nodes
- programmatic get/set value behavior
- destroy/teardown support
- form integration compatibility

## Search Behavior Requirements

Search should not flatten context away completely.

Recommended behavior:

- when a child matches search, its parent category remains visible
- non-matching sibling leaves may be hidden while the matching parent remains shown
- empty states should clearly say no matching options exist

Example:

Search term:

```text
ambu
```

Visible result should still show context like:

```text
Medical
  Ambulance
```

not just:

```text
Ambulance
```

## UI Behavior Requirements

Recommended UI behavior:

- collapsed by default
- categories visually distinct from selectable leaves
- clear indentation for hierarchy
- selected value shown in the closed trigger using the leaf label
- optional helper formatting may also show `Category / Leaf` in trigger text when useful

## Non-Goals

This proposal does not ask Helper to:

- become a generic editable tree manager
- support drag-and-drop taxonomy editing
- replace `ui.tree`
- replace `ui.select` for ordinary flat option lists

It only asks for a supported selection control for hierarchical option data.

## Concrete Hotline Example

Hotline’s current `Add Inventory` form would ideally move from:

```js
{
  type: "ui.select",
  name: "resource_type_id",
  searchable: true,
  options: [
    { value: "2", label: "Medical / Ambulance" },
    { value: "1", label: "Medical / First Aid Kit" },
    { value: "4", label: "Supply / Food & Water Supplies" },
  ],
}
```

to:

```js
{
  type: "ui.treeSelect",
  name: "resource_type_id",
  searchable: true,
  nodes: [
    {
      id: "medical",
      label: "Medical",
      children: [
        { id: "2", label: "Ambulance" },
        { id: "1", label: "First Aid Kit" },
      ],
    },
    {
      id: "supply",
      label: "Supply",
      children: [
        { id: "4", label: "Food & Water Supplies" },
      ],
    },
  ],
}
```

## Acceptance Criteria

Helper implementation should include:

- a stable public API for tree-select creation
- form-modal support for `ui.treeSelect`
- demo coverage showing category + child selection
- search behavior demos
- regression coverage for:
  - search + selection
  - keyboard navigation
  - clearing selection
  - single-select and multi-select behavior if multi-select is supported in V1

## Recommendation To Helper

Treat this as a real shared component gap, not a Hotline-only preference.

The pattern is likely reusable across PBB apps for:

- categorized resource catalogs
- grouped incident types
- hierarchical locations
- grouped settings or permissions pickers

Hotline happens to expose the need clearly, but the component belongs in Helper if it is going to be done at all.
