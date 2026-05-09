# PBB Helper Proposal: Team Assignment Cancel Reason Modal Contract

## Summary

`incidentTeamsAssignments(...)` currently uses native browser prompts for the team-assignment cancel flow:

- reason code via `window.prompt(...)`
- optional free-text details for `other`
- final confirmation via `confirmCancel(...)`

Hotline Beta wants that flow to stay Helper-owned, but on shared Helper modal UI rather than browser prompt dialogs.

The current Helper preset `createReasonFormModal(...)` is close, but it is not a clean fit for this case because it requires `reasonDetails` for every reason. The current team-assignment behavior only requires extra details when the operator chooses `other`.

## Current Gap

Today, `incident.teams.assignments.editor.js` owns the cancel-reason collection path internally through prompt-based UI.

Practical downstream impact:

- the cancel flow is visually inconsistent with newer Helper dialogs already used elsewhere
- host apps cannot cleanly swap the prompt path to Helper modal UI without forking the editor behavior
- downstream apps are pushed toward rebuilding a parallel reason form even though Helper already has most of the needed form/modal infrastructure

This is not a missing capability in the host app. It is a narrow mismatch between the existing cancel workflow and the current Helper reason-modal preset contract.

## Current Hotline Need

In Hotline Beta operator workbench, cancelling a dispatched team assignment should work like this:

1. operator clicks `Cancel`
2. Helper opens a modal to collect cancellation reason
3. operator selects a reason code
4. if reason code is `other`, Helper requires a details field
5. Helper runs final confirmation
6. host app receives `onCancel(assignmentId, fromStatus, reasonCode, reasonNote)`

Important current rule:

- `reasonNote` is required only when `reasonCode === "other"`

That rule is why `createReasonFormModal(...)` cannot be dropped in as-is for this flow.

## Why This Belongs In Helper

This is still the shared incident-team-assignment editor’s own workflow boundary.

Helper already owns:

- the cancel action entry point in `incidentTeamsAssignments(...)`
- the existing cancellation-reason vocabulary
- the prompt-based reason collection path
- the final cancel callback contract

Because Helper already owns the flow shape, downstream apps should not need to recreate a separate cancel-reason modal just to replace native prompts with Helper UI.

If Hotline implements a local replacement, that duplicates behavior that already lives in the shared editor.

## Proposed Direction

Add a narrow Helper-supported modal path for cancellation reasons.

Recommended first-pass direction:

- keep the existing cancel callback contract
- keep current browser-prompt behavior as fallback for backward compatibility
- let host apps opt into a Helper-modal reason collection path

## Recommended API Shape

### Option A: extend `incidentTeamsAssignments(...)`

Add an optional host hook:

```js
requestCancelReason(fromStatus, meta)
```

Expected return shape:

```js
{
  reasonCode: 'mechanical_issue',
  reasonNote: '',
}
```

or:

```js
{
  reasonCode: 'other',
  reasonNote: 'Unit reassigned by incident command.',
}
```

Semantics:

- if provided, Helper calls `requestCancelReason(...)` instead of using native prompts
- if not provided, Helper keeps the current prompt-based fallback
- Helper still owns the surrounding cancel flow and still calls `confirmCancel(...)` and `onCancel(...)`

This is the narrowest additive contract and keeps host integration small.

### Option B: extend `createReasonFormModal(...)`

Add a preset option that supports conditional details requirements, for example:

```js
createReasonFormModal({
  reasonOptions: [...],
  detailsRequiredWhen: {
    reasonCode: ['other'],
  },
});
```

or equivalent preset semantics documented by Helper.

If Helper prefers this route, `incidentTeamsAssignments(...)` could then adopt the preset internally instead of native prompts.

## Recommended First Pass

From Hotline Beta’s side, the most useful immediate fix is:

1. add `requestCancelReason(...)` to `incidentTeamsAssignments(...)`
2. document the expected payload/result shape
3. preserve current prompt fallback for existing consumers

This avoids forcing a rushed preset redesign just to unblock downstream modal consistency.

After that, Helper can decide whether `createReasonFormModal(...)` should grow a conditional-required contract for broader reuse.

## Required Behavior

If Helper accepts a modal-based cancel reason flow, the supported behavior should be:

- reason code is always required
- reason details are required only for `other`
- canceling the modal aborts the cancel action cleanly
- final `confirmCancel(fromStatus, reasonCode, reasonNote)` still runs after reason collection
- `onCancel(...)` signature stays unchanged

## Non-Goals

This proposal does not ask Helper to:

- move persistence into Helper
- move cancel API calls into Helper
- redesign the entire reason-form preset family immediately
- remove the current prompt path without backward compatibility

The ask is narrow: let the shared team-assignment editor support Helper modal UI for cancel reasons without forcing downstream apps to duplicate that flow locally.

## Recommendation To Helper

Treat this as a small editor-contract gap, not a Hotline-only customization request.

The shared editor already owns the cancellation flow; it should be possible for host apps to keep that flow on Helper UI using a supported modal path. The narrowest near-term solution is an additive `requestCancelReason(...)` hook with prompt fallback. If Helper wants a broader follow-through afterward, conditional-required support in `createReasonFormModal(...)` would make the preset reusable for this and similar workflows.
