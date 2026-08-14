# PBB Hotline Callback Protocol Implementation Checklist

**Status:** Ready for implementation planning  
**Related proposal:** `docs/pbb-hotline-callback-protocol-development-proposal.md`  
**Scope boundary:** Incident-only callbacks initiated by the assigned operator

## Product Rules

- Callback protocol is a core Hotline feature, not a feature-flagged optional module.
- Callback cases are allowed only for existing incidents.
- Callback cases must not be created directly from fallback drops, call attempts, or public/citizen-only records.
- Busy-state fallback drops must be converted to incidents before callback can begin.
- Callback actions may only be performed by the incident's current assigned operator.
- During handover, the incident assignment must change first; callback responsibility follows the incident.
- Failure to reach a citizen must not automatically resolve, discard, or defer an incident.

## Data Model

- Add `callback_cases`.
- Add `callback_attempts`.
- `callback_cases` should include:
  - `incident_id`
  - `citizen_id`
  - `operator_id`
  - nullable `source_call_session_id`
  - `reason`
  - `priority`
  - `status`
  - `due_at`
  - nullable `completed_at`
  - nullable `final_disposition`
  - timestamps
- `callback_attempts` should include:
  - `callback_case_id`
  - `operator_id`
  - `attempt_number`
  - nullable `started_at`
  - nullable `ended_at`
  - `channel`
  - `result`
  - nullable `call_attempt_id`
  - nullable `call_session_id`
  - nullable `note`
  - timestamps
- Enforce one open callback case per `incident_id` and `reason`.
- Do not add `fallback_drop_id`, `subject_type`, or `subject_id`.

## Services

- Add a callback service responsible for all transactional state changes.
- Service must validate:
  - incident exists;
  - incident status is `Active` or `Deferred` when callback requires reconnect;
  - authenticated operator is the incident's assigned operator;
  - no duplicate open callback exists for the same `incident_id` and `reason`;
  - completed callbacks include a final disposition.
- Reuse `CallRoutingService::startReconnectAttempt()` for incident callback call initiation.
- Do not use `startDirectedAttempt()` for fallback drops or pre-incident callbacks.
- Store callback attempt history even when a reconnect request fails because of eligibility or duplicate active reconnect.

## Settings

- Store callback policy settings in DB-backed runtime settings, not `.env`.
- Add defaults for:
  - first callback SLA seconds for dropped active incident calls;
  - max additional attempts;
  - retry window minutes;
  - overdue warning threshold if needed.
- Expose settings through the existing Admin Runtime Settings surface if the implementation includes UI.

## Automatic Creation

- Create callback cases only for policy-defined incident events.
- Candidate V1 event:
  - active in-progress incident call unexpectedly disconnects before operator disposition.
- Explicit operator-created callback is allowed from an existing assigned incident.
- Do not create callback cases for:
  - fallback drops before conversion;
  - normal completed calls;
  - pre-answer call attempts;
  - deliberate citizen cancellation;
  - incidents not assigned to the current operator.

## Operator UI

- Add callback visibility inside the operator workflow.
- Show:
  - due/overdue state;
  - reason;
  - incident number;
  - citizen name/contact;
  - previous attempts;
  - next action.
- Actions:
  - call now;
  - record attempt result;
  - schedule next attempt;
  - complete with disposition.
- Do not provide a separate callback claim action; assignment follows the incident.
- If an incident is reassigned, callback UI should appear for the new assigned operator.

## API

- Add operator-only endpoints such as:
  - `GET /api/operator/callbacks`
  - `POST /api/operator/callbacks`
  - `POST /api/operator/callbacks/{callbackCase}/call`
  - `POST /api/operator/callbacks/{callbackCase}/attempts`
  - `POST /api/operator/callbacks/{callbackCase}/complete`
- Every endpoint must reject non-assigned operators.
- Every mutating endpoint must use the callback service.
- Return clear `409` or `422` responses for duplicate, stale, or invalid transitions.

## Handover

- Callback cases must not be independently transferred.
- Incident reassignment is the handover mechanism.
- Open callback cases should remain attached to the incident and become actionable by the new assigned operator.
- Audit callback visibility/ownership changes caused by incident reassignment where practical.

## Reporting

- Track:
  - callback cases created;
  - first-attempt SLA compliance;
  - median/P95 first-attempt delay;
  - attempt count per case;
  - answer rate;
  - unresolved callbacks at shift end;
  - overdue callbacks by operator.

## Tests

- Incident-only callback creation works.
- Fallback drop cannot directly create a callback.
- Converted fallback incident can create callback after assignment.
- Non-assigned operator cannot create callback.
- Non-assigned operator cannot call, update, or complete callback.
- Duplicate open case for same `incident_id` and `reason` is rejected or idempotently returned.
- Existing `startReconnectAttempt()` validation remains authoritative.
- Callback attempt is logged when reconnect initiation succeeds.
- Callback attempt failure is logged when reconnect initiation fails.
- Completing without disposition is rejected.
- Incident reassignment transfers callback responsibility.
- Normal call completion does not create callback.
- Pre-answer cancellation does not create callback.
- Settings defaults are seeded safely and non-destructively.
- Baseline schema and `release.json` migration metadata are updated if migrations are added.

## Agent Prompt

Use this prompt for the implementing agent:

```text
Please implement the PBB Hotline callback protocol using the incident-only boundary documented in:

- C:\wamp64\www\pbb\hotline\docs\pbb-hotline-callback-protocol-development-proposal.md
- C:\wamp64\www\pbb\hotline\docs\pbb-hotline-callback-protocol-implementation-checklist.md

Work in a clean worktree under:
C:\wamp64\www\pbb\hotline-branches\callback\incident-only-protocol

Branch name:
callback/incident-only-protocol

Hard constraints:
- Callback cases are only for existing incidents.
- Do not create callback cases directly from fallback drops.
- Fallback drops must first be converted to incidents before callback can be initiated.
- Do not use fallback_drop_id, subject_type, or subject_id in the callback schema.
- Do not use startDirectedAttempt() for fallback/pre-incident callback.
- Only the incident's currently assigned operator may create/call/update/complete a callback.
- During handover, incident assignment changes first; callback responsibility follows the incident.
- Store callback policy settings in DB-backed settings, not .env.
- Do not build an installer bundle.

Expected implementation:
- Add callback_cases and callback_attempts migrations/models.
- Add a transactional callback service.
- Reuse CallRoutingService::startReconnectAttempt() for callback call initiation.
- Add operator APIs for list/create/call/attempt/complete.
- Add minimal operator UI for due/overdue callbacks if feasible in this branch.
- Add focused tests for assigned-operator enforcement, fallback rejection, duplicate prevention, reconnect reuse, attempt history, completion validation, and reassignment behavior.
- Update baseline schema and release.json migration metadata if migrations are added.
- Update docs if implementation decisions differ from proposal.

Validation:
- Run focused PHP tests for callback, reconnect/call routing, fallback drop interaction if present, and settings.
- Run npm build only if JS/UI changes are made.
- Run git diff --check.

Return PR link, branch, commit, validation results, known risks, and confirm no Hotline installer bundle was built.
```
