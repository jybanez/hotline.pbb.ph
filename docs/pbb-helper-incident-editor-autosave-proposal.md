# PBB Helper Proposal: Incident Editor Event Contract Alignment

## Context

Hotline Beta is integrating the shared Helper incident editors into the operator workbench for two editable sections:

- Incident Types
- Dispatch Team Assignments

Both sections need predictable host-app autosave behavior when the incident is in an editable state. The current Helper components expose enough low-level callbacks to detect edits, but they do not expose a normalized post-mutation event contract. That pushes too much event reconstruction work into each downstream app.

## Current Helper Surface

### Incident Types helper

Current callbacks available in the Helper component include:

- `onAddIncidentType`
- `removeIncidentType`
- `onFieldChange`
- `onResourceChange`
- `onOpenDrawer`
- `onCloseDrawer`

### Team Assignments helper

Current callbacks available in the Helper component include:

- `onAssignTeam`
- `onDelete`
- `onCancel`
- `onStatusNext`
- `onAllocateChange`
- `onContactChange`
- `onNoteAdd`
- confirmation hooks such as `confirmDelete`, `confirmCancel`, `confirmStatus`, and `confirmContactEdit`

## Problem

The helpers currently emit granular edit events, but not a normalized mutation contract.

In practice, each downstream app must currently do all of the following itself:

- reconstruct the updated item or list from multiple callback shapes
- map Helper-specific callback arguments into API payloads
- debounce autosave behavior manually
- own persistence timing and retry behavior
- own optimistic update and rollback behavior
- refresh local state manually after successful saves

The main gap is not missing edit detection. The main gap is the lack of a normalized post-mutation event surface that gives host apps the resulting item or list directly.

## Observed Downstream Impact In Hotline Beta

### Team Assignments

Hotline Beta was able to wire host-owned autosave for team assignments because matching operator endpoints already exist in the app:

- create assignment
- update assignment status/contact/resource allocation
- delete assignment

Even with working endpoints, the integration still required app-local debounce, payload reconstruction, refresh logic, and save-state handling.

### Incident Types

Hotline Beta cannot finish incident-type autosave cleanly with the current app/backend shape because the app still needs persistence endpoints for:

- attaching/removing incident types on an incident
- saving incident-type detail values
- saving incident resources needed

But even once those endpoints exist, the current Helper callback model still leaves too much host-side event stitching.

## Proposal

### 1. Add a unified item-level change event

For both incident editors, expose a normalized item callback such as:

```js
onItemChange(nextItem, meta)
```

Where `meta` can describe why the change happened, for example:

- `reason: 'add'`
- `reason: 'remove'`
- `reason: 'field'`
- `reason: 'resource'`
- `reason: 'status'`
- `reason: 'contact'`
- `reason: 'note'`

This lets downstream apps persist a single updated item without reconstructing it from primitive arguments.

### 2. Add a unified list-level change event

Expose a normalized list callback such as:

```js
onChange(nextList, meta)
```

This is useful for host apps that prefer to persist the whole editor state or keep a single source of truth.

### 3. Emit full updated objects, not only primitive args

For example, instead of only:

```js
onFieldChange(incidentTypeId, fieldKey, value)
```

also emit the full updated incident-type item.

The same applies to team assignment status/contact/resource changes.

### 4. Provide stable client-side keys for unsaved rows

When the Helper creates new unsaved items, it should always include a stable `_client_key` or equivalent local identifier.

This makes optimistic UI, save reconciliation, and rollback much safer for downstream apps.

### 5. Document exact callback timing and payload shape

For each callback, document:

- when it fires
- whether it is pre-change or post-change
- whether it emits a primitive diff, a full item, or a full list
- whether the callback is expected to mutate host state immediately or await host confirmation

This is currently discoverable only by reading Helper source.

## Explicit Non-Goals For First Pass

To align with current Helper-side feedback, the first pass should not make Helper responsible for:

- persistence transport
- debounce or autosave timing
- optimistic-save ownership
- rollback/conflict handling
- canonical save-state orchestration

Those concerns should remain host-app responsibilities. Helper should remain the rendering/editing layer, but with a stronger normalized event contract.

## Requested Direction

The recommendation for the Helper team is:

- keep existing granular callbacks for backward compatibility
- add normalized `onItemChange(nextItem, meta)` and `onChange(nextList, meta)` contracts
- add stable local IDs for unsaved items
- document the event contract explicitly in the Helper docs

## Why This Matters

This change would reduce repeated adapter code across downstream apps and make host-owned autosave integrations more consistent in:

- Hotline Beta
- HQ
- Workspace
- Realtime admin/editor surfaces
- other Helper-first PBB browser apps

The current components are close. The proposed first pass keeps ownership boundaries narrow while still removing the highest-friction event-reconstruction work from downstream apps.
