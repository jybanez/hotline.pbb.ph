# PBB Hotline Beta Incident Types Autosave Refactor Plan

Date: 2026-04-18

Status: Draft

## Summary

Hotline Beta should stop treating Incident Types autosave as a whole-list sync.

Current behavior posts the full `incident_types` list to one endpoint:

- `POST /api/operator/incidents/{incident}/incident-type-details`

That first slice was useful to get persistence working quickly, but it is now the wrong shape for a workbench editor that can contain multiple incident-type cards with many fields and resource rows.

The next Beta step should be to refactor Incident Types autosave to match the narrower save model already used by Team Assignments:

- add/remove incident type saved separately
- field/detail values saved separately
- resource-needed values saved separately

## Current Problem

Whole-list sync creates three concrete problems:

1. Request size and processing time grow with the number of incident-type cards.
2. In-flight saves can overlap, so older snapshots can overwrite newer local edits.
3. Editing one card can accidentally affect sibling cards because the payload includes unrelated card state.

Observed real downstream symptom:

- editing values under one incident type can cause another incident type to revert or lose values if a stale or incomplete list snapshot wins the race

This is the same reason Team Assignments felt more stable after Beta moved to smaller concern-specific saves.

## Recommended Direction

Replace the current full-list sync with narrower operator endpoints and narrower frontend save queues.

Recommended persistence units:

- Incident Type card membership
- Incident Type detail field value
- Incident Type resource-needed quantity

Do not make Helper own this. Keep the current ownership boundary:

- Helper emits `onItemChange(nextItem, meta)` / `onChange(nextList, meta)`
- Beta maps those events into app-owned saves
- Beta owns debounce, API transport, optimistic state, retries, and server reconciliation

## Proposed API Shape

### 1. Attach one Incident Type to an Incident

Use when the operator adds a new incident-type card.

Route:

```text
POST /api/operator/incidents/{incident}/incident-types
```

Request:

```json
{
  "incident_type_id": 12
}
```

Response:

```json
{
  "ok": true,
  "item": {
    "id": 12,
    "incident_type_id": 12,
    "name": "Landslide",
    "detail_entries": [],
    "resources_needed": []
  }
}
```

Notes:

- this should create the incident-type attachment only
- no full incident payload is needed for the normal success path
- response should be the canonical saved card payload for that one item

### 2. Remove one Incident Type from an Incident

Use when the operator removes a card.

Route:

```text
DELETE /api/operator/incidents/{incident}/incident-types/{incidentType}
```

Response:

```json
{
  "ok": true
}
```

Notes:

- this should remove the attachment plus all related detail/resource rows for that incident-type pair

### 3. Save one Incident Type Detail Field

Use when a field under one card changes.

Route:

```text
POST /api/operator/incidents/{incident}/incident-types/{incidentType}/details/{field}
```

Request:

```json
{
  "field_value": "3"
}
```

Response:

```json
{
  "ok": true,
  "entry": {
    "incident_id": 81,
    "incident_type_id": 12,
    "field_id": 44,
    "field_key": "people_missing",
    "field_value": "3"
  }
}
```

Notes:

- empty/blank values should delete or clear that single detail row, not trigger a whole-card rewrite
- validation stays field-specific

### 4. Save one Resource Needed Value

Use when one resource quantity under one card changes.

Route:

```text
POST /api/operator/incidents/{incident}/incident-types/{incidentType}/resources/{resourceType}
```

Request:

```json
{
  "quantity_needed": 2,
  "notes": null
}
```

Response:

```json
{
  "ok": true,
  "entry": {
    "incident_id": 81,
    "incident_type_id": 12,
    "resource_type_id": 7,
    "quantity_needed": 2,
    "notes": null
  }
}
```

Notes:

- `quantity_needed <= 0` should delete the one resource-needed row for that incident-type/resource-type pair
- this should not touch sibling resources or sibling cards

## Optional Narrower Alternative

If Beta wants fewer routes while still leaving whole-list sync behind, an acceptable midpoint is:

- one route per incident-type card

Example:

```text
POST /api/operator/incidents/{incident}/incident-types/{incidentType}
```

Request:

```json
{
  "detail_entries": [...],
  "resources_needed": [...]
}
```

This is still much safer than whole-list sync because the conflict surface is limited to one card, not the full list.

However, the preferred direction is still separate detail/resource saves because that matches Team Assignments more closely and keeps race windows smaller.

## Frontend Mapping Plan

### Current Helper event usage

Use Helper’s normalized item callback:

```js
onItemChange(nextItem, meta)
```

Map by `meta.reason`.

### Recommended Beta mapping

- `reason: "add"`
  - call attach endpoint
  - replace optimistic card with canonical server item

- `reason: "remove"`
  - call remove endpoint
  - remove the card locally

- `reason: "field"`
  - identify `fieldKey` / `fieldId` from `meta` or `nextItem.detail_entries`
  - queue a field-specific save keyed by:
    - `detail:${incidentTypeId}:${fieldId || fieldKey}`

- `reason: "resource"`
  - identify `resourceTypeId` from `meta`
  - queue a resource-specific save keyed by:
    - `resource:${incidentTypeId}:${resourceTypeId}`

### Debounce recommendation

Field/resource saves should be debounced independently, similar to Team Assignments:

- text/number field edits: `300-400ms`
- select changes: immediate or near-immediate
- resource quantity edits: `300-400ms`

### Reconciliation rule

Do not replace the full `payload.incident_types` list from each successful save response.

Instead:

- patch only the affected card
- patch only the affected detail/resource row inside that card
- keep the rest of the local list intact

This is the main stability win.

## Backend Implementation Plan

Recommended Beta backend classes:

- `IncidentTypeAttachmentService`
- `IncidentTypeDetailService`
- `IncidentTypeResourceNeededService`

Or, if Beta prefers the current lighter service style:

- extend `IncidentTypeWorkbenchService` temporarily, but split its public methods by concern:
  - `attach(...)`
  - `detach(...)`
  - `saveDetailEntry(...)`
  - `saveResourceNeeded(...)`

Recommended controller surface:

- keep `IncidentController` for incident-owned routes if you want minimal route churn
- but route handlers should still call narrow service methods rather than one big sync method

## Migration Strategy

Recommended Beta implementation sequence:

1. Add attach/remove routes first.
2. Add one-detail save route.
3. Add one-resource-needed save route.
4. Update the frontend adapter to use narrow saves from `onItemChange(...)`.
5. Keep the old whole-list sync route temporarily behind the scenes until the new flow is stable.
6. Remove the old whole-list autosave usage from the workbench.

## Immediate UX Benefits

Expected improvements after the refactor:

- smaller payloads
- faster autosave acknowledgements
- less cross-card interference
- fewer race-condition reverts
- better fit for long incident-type lists
- cleaner parity with Team Assignments save behavior

## Non-Goals

This plan does not ask Beta to:

- move persistence into Helper
- redesign the Helper incident-types editor
- add helper-owned save indicators first
- change read-model payload shape for initial workbench load unless needed

The goal is narrow:

- stop using whole-list Incident Types autosave in the operator workbench
- move to smaller concern-specific saves that behave more like Team Assignments

## Recommendation

Beta should treat the current whole-list Incident Types autosave as a temporary bridge, not the long-term design.

The next implementation pass should move Incident Types to concern-level saves, with the frontend patching only the affected card/entry after each successful response. That is the cleanest way to remove the current race/conflict class without changing the Helper boundary.
