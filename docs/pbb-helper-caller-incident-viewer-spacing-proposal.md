# PBB Helper Proposal: Incident Viewer Top Spacing in Hosted Tabs

## Context

Hotline Beta uses shared Helper incident viewers inside the caller-side `Current Incident Overlay`.

The caller overlay mounts:

- `incidentTypesHelper(...)` in non-editable/viewer mode
- `incidentAssignmentsHelper(...)` in non-editable/viewer mode

inside a Helper `createTabs(...)` panel.

After flattening Beta-owned wrapper layers, the remaining visual issue is still an excessive top gap above the first rendered incident card on the caller side.

## Observed Cause

The extra space is coming from shared Helper spacing defaults rather than Beta-owned wrapper chrome:

- Helper `ui-tabpanel` already introduces the host panel region.
- The mounted Helper incident roots (`.hh-incident-types`, `.hh-incident-teams-assignments*`) also apply root padding.
- In viewer mode, this causes the first card to sit visibly lower than expected when the component is hosted directly inside a tab panel.

This is most noticeable in compact hosted surfaces like Hotline Beta caller `Current Incident Overlay`.

## Request

Please review the shared incident viewer root spacing for hosted/tabbed usage and tighten the top offset in the shared component contract rather than requiring downstream per-app CSS overrides.

Narrow goal:

- reduce wasted top space above the first incident/assignment card
- keep internal card spacing intact
- avoid forcing downstream projects to add caller-only CSS hacks

## Preferred Direction

One of these shared approaches would work:

1. Reduce top padding on Helper incident root containers in viewer mode.
2. Add a hosted/compact option for the Helper incident viewers that trims root top padding.
3. Adjust the first-child spacing rule so the first card aligns tighter without changing overall section spacing.

## Important Boundary

Beta does **not** want to keep a local CSS override for this.

The issue appears in shared Helper-owned viewer spacing and should be fixed upstream so caller/operator/other hosted tabs stay visually consistent across downstream apps.

## Beta Notes

- Beta already removed an unnecessary local wrapper layer in the caller overlay.
- A temporary Beta-only CSS tweak was intentionally backed out so the shared issue can be fixed in Helper properly.
- This proposal is about spacing only, not behavior or data contracts.
