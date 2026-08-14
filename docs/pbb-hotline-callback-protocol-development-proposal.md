# PBB Hotline Callback Protocol Development Proposal

**Status:** Proposal for Codex review and implementation
**Product:** PBB Hotline
**Repository:** `jybanez/hotline.pbb.ph`
**Priority:** Pilot-critical

## 1. Problem

Hotline already supports reconnect behavior for open `Active` or `Deferred` incidents, but the pilot needs a formal, auditable **callback protocol** for existing incident records. Callback compliance should be measurable by shift and operator without expanding callback behavior into fallback intake or pre-incident call attempts.

## 2. Goal

Implement callback tracking as a first-class operational workflow strictly within the bounds of an existing Hotline incident. Every required callback should have an owner, due time, attempt history, result, and final disposition.

The callback protocol must only be initiated by the operator currently assigned to the incident. If a busy-state fallback drop needs follow-up, it must first be converted to an incident. If a callback needs to be handled by a different operator during shift handover, the incident assignment must be transferred first.

## 3. Initial Pilot Policy Targets

These values should be configurable rather than hard-coded:

- dropped active emergency call for an existing incident: first callback attempt target **within 30 seconds** where contact is available;
- busy-state fallback drop: no callback can be initiated directly from the fallback record; it must first be converted to an incident, then the assigned operator may initiate the callback;
- potentially urgent unreachable citizen: up to **2 additional attempts within the following 5 minutes**;
- callback attempts must be logged;
- failure to reach a citizen must not automatically resolve an incident.

Create settings for the actual numbers so the pilot can revise them without schema/code changes.

## 4. Proposed Domain Model

Create `callback_cases` and `callback_attempts`, or a generalized model if the repository already has a suitable contact-attempt abstraction.

The domain model is incident-bound. Do not model fallback drops or standalone call attempts as callback subjects.

`callback_cases` suggested fields:

- `id`
- `incident_id`
- `citizen_id`
- `operator_id`: must match the incident's current assigned operator when the callback is created or attempted
- `source_call_session_id` nullable, for dropped/interrupted in-progress sessions that triggered the callback
- `reason`: `call_dropped`, `reconnect_required`, `operator_followup`, `other`
- `priority`
- `status`: `pending`, `in_progress`, `completed`, `cancelled`
- `due_at`
- `completed_at`
- `final_disposition`
- timestamps

`callback_attempts`:

- `callback_case_id`
- `operator_id`
- `attempt_number`
- `started_at`, `ended_at`
- `channel`: start with `pbb_call`; optional `phone`/`radio` later if policy allows
- `result`: `answered`, `no_answer`, `declined`, `unreachable`, `wrong_contact`, `technical_failure`, `cancelled`
- `call_attempt_id` / `call_session_id` nullable, populated when the attempt launches an in-app reconnect call
- `note`

Enforce at most one open callback case per `incident_id` and `reason`, unless product policy later defines recurring callback cycles.

## 5. Integration with Existing Reconnect

Current `CallRoutingService::startReconnectAttempt()` already validates:

- operator eligibility;
- citizen eligibility;
- incident ownership;
- incident belongs to citizen;
- incident is `Active` or `Deferred`;
- no duplicate reconnect is already in progress.

Prefer invoking this service for incident callbacks rather than creating a parallel call mechanism. Add a callback service that orchestrates case state and calls `startReconnectAttempt()` when appropriate.

Do not use `startDirectedAttempt()` for fallback drops or pre-incident callbacks. Fallback drops are outside the callback protocol until they are converted to an incident.

## 6. Automatic Callback Case Creation

Codex should review current call end/outcome handling and add callback cases only for policy-defined events, for example:

- active in-progress call unexpectedly disconnects before operator disposition;
- operator explicitly marks follow-up required.

Do not create callback cases for fallback drops, normal completed calls, pre-answer call attempts, or deliberate citizen cancellation unless they are first represented by an existing incident and the assigned operator explicitly initiates or policy requires an incident callback.

## 7. Operator UI

Add a callback queue showing:

- overdue / due soon;
- reason;
- related incident;
- citizen;
- prior attempts;
- next required action.

Actions:

- call now;
- record result;
- schedule next attempt within allowed policy;
- complete with disposition.

Overdue callbacks must be visually prominent.

## 8. API

Suggested operator APIs:

- `GET /api/operator/callbacks`
- `POST /api/operator/callbacks/{id}/attempts`
- `POST /api/operator/callbacks/{id}/complete`

If call initiation is separate:

- `POST /api/operator/callbacks/{id}/call`

All state changes should live in a service with transactional validation.

Every endpoint must verify that the authenticated operator is the incident's current assigned operator. Callback ownership follows incident ownership.

## 9. Shift Handover

Open callback cases must appear in Shift Queue Review. Logging out or ending a shift must not orphan callback cases. Because callbacks are incident-bound, handover must happen by transferring the incident assignment first.

After incident reassignment, open callback cases should follow the new assigned operator and audit the assignment change source. Do not reassign callback cases independently of the incident.

## 10. Reporting

Track:

- callbacks required;
- first-attempt within SLA;
- median/P95 first-attempt delay;
- callback answer rate;
- repeated-attempt count;
- unresolved callbacks at shift end;
- overdue callbacks by shift/operator.

## 11. Audit

Record:

- callback case creation reason;
- incident assignment at callback creation and any later incident reassignment affecting callback ownership;
- each attempt and result;
- completion/disposition;
- SLA breach reason if captured.

## 12. Tests

- dropped active call creates exactly one callback case when applicable;
- fallback drop cannot create callback case until converted to incident;
- normal call completion does not;
- incident callback reuses reconnect validation;
- non-assigned operator cannot create, call, update, or complete callback case;
- duplicate concurrent reconnect is rejected;
- due/overdue calculation is correct;
- callback cannot be completed without disposition;
- incident reassignment moves callback responsibility without losing history;
- callback case cannot silently disappear when operator logs out;
- metrics calculate first-attempt SLA correctly.

## 13. Acceptance Criteria

- Every policy-required callback is durable and measurable.
- Operators can see due and overdue callbacks.
- Existing reconnect flow remains authoritative for incident reconnect calls.
- No callback case is orphaned at shift change because callback responsibility follows incident assignment.
- Weekly Pilot Review can report callback count, SLA compliance, unresolved backlog, and outcomes.


## Repository Basis

This proposal is grounded in the current `main` branch of `jybanez/hotline.pbb.ph` as reviewed on 2026-08-14. Relevant current behavior includes:

- Citizen call attempts are created through `App\Support\Calls\CallRoutingService`. A new call is rejected when Hotline availability is not green or no operator is available.
- A verified Hotline incident is represented by `App\Domain\Incidents\Models\Incident`.
- Current incident status enum: `Active`, `Deferred`, `Discarded`, `Resolved`.
- Operator incident management is implemented under `App\Http\Controllers\Api\Operator\IncidentController`.
- Team dispatch is a separate lifecycle with `assigned`, `requested`, `accepted`, `en_route`, `on_scene`, `completed`, `cancelled`.
- Resolving an incident is already blocked while open team assignments remain.
- The operator is the incident owner and dispatch operator; Command is not part of normal incident handling.

Codex must inspect the current repository before implementation and treat code as authoritative where this proposal conflicts with stale generated documentation.
