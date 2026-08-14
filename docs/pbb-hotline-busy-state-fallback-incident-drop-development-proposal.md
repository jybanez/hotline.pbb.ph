# PBB Hotline Busy-State Fallback / Incident Drop Development Proposal

**Status:** Proposal for Codex review and implementation
**Product:** PBB Hotline
**Repository:** `jybanez/hotline.pbb.ph`
**Priority:** Pilot-critical

## 1. Problem

Today, a citizen starting a new Hotline call receives an immediate failure when no operator is available. That behavior is operationally honest, but it creates a blind spot: a genuine emergency can fail to enter the operator workflow during the exact period when every operator is busy.

The pilot needs a fallback that captures a minimum emergency contact without pretending that an operator has already verified the incident. The fallback must preserve the current principle that a normal incident record is not automatically created merely because a citizen attempted to call.

## 2. Goal

Add a **busy-state fallback incident drop** that lets a citizen leave a minimal, reviewable emergency contact when no operator can accept a new call. The fallback becomes a separate auditable object that an operator must review, call back, and either convert into a verified incident or close with a disposition.

## 3. Non-Goals

- Do not automatically create a normal `incidents` row on busy-state failure.
- Do not create a `call_attempts` row for a busy-state failure; no operator was routed, so no call attempt record exists.
- Do not create a conventional hold queue that leaves citizens silently waiting.
- Do not change the existing incident status enum.
- Do not make Command responsible for reviewing fallback contacts.
- Do not bypass current Account/citizen identity or authorization rules.
- Do not treat fallback drop media as incident/SITREP/Relay evidence until an operator converts the drop into a verified incident.

## 4. Proposed Domain Model

Create a dedicated entity such as `fallback_incident_drops` or `emergency_contact_drops`. Suggested fields:

- `id`
- `citizen_id` required for V1; anonymous fallback is out of scope until the citizen identity model explicitly supports it
- `status`: `new`, `claimed`, `callback_pending`, `converted`, `closed`
- `reason`: initially `all_operators_busy`; extensible later
- `citizen_latitude`, `citizen_longitude`, accuracy fields where available
- `quick_category` nullable
- `short_description` nullable
- `callback_contact_snapshot` if a safe contact field is available through Account/Hotline
- `created_at`, `claimed_at`, `callback_due_at`, `converted_at`, `closed_at`
- `claimed_by_operator_id` nullable
- `converted_incident_id` nullable FK
- `closure_disposition` nullable
- `closure_note` nullable

Add a companion attachment table for optional citizen photo evidence:

- `fallback_incident_drop_attachments`
- `fallback_incident_drop_id`
- media/attachment identity fields
- normalized WebP metadata for uploaded photos
- original filename, MIME type, byte size, checksum where available
- created timestamps

Photo handling must follow Hotline's secure media posture: store metadata and private storage references only, never public `/storage/...` URLs. New photos should normalize to authoritative WebP using the existing image normalization approach. Invalid, corrupt, oversized, unsupported, or unwritable image uploads should fail loudly.

Add an audit/history table if existing activity-log infrastructure cannot cleanly represent fallback state changes.

## 5. Citizen Workflow

When `CallRoutingService::startNewAttempt()` determines that Hotline is unavailable because no operator is available:

1. Return a structured busy-state response, not only a generic 409 message.
2. Citizen UI explains that all operators are busy and offers **Leave emergency details for callback**.
3. Citizen submits the minimum fallback form.
4. System confirms receipt with an immutable drop/reference ID and clear language that the report is **awaiting operator review**, not yet a verified incident.
5. Citizen may cancel or update only within a narrowly defined pre-claim window if desired; simplest V1 can be immutable after submission.

Minimum citizen capture should prefer:

- current location / GPS if available;
- a short emergency category or “other”;
- brief description;
- callback consent/contact context.
- optional photo evidence when available and safe to upload.

Do not force a long questionnaire while the citizen may be in distress.

## 6. Operator Workflow

Add a **Fallback / Callback** section to the operator dashboard. Operators should see:

- age of drop;
- approximate location;
- quick category;
- citizen identity/contact context;
- callback SLA countdown;
- claim state.

Required actions:

1. Claim the drop.
2. Attempt callback under the Callback Protocol proposal.
3. If verified, create a normal Hotline incident through a dedicated conversion service.
4. If duplicate, link to the primary incident under Duplicate Detection/Merge.
5. If spam/prank/invalid, close with a controlled disposition.
6. If unreachable but potentially serious, keep pending or escalate operationally according to policy; do not silently discard.

Conversion must preserve provenance: the resulting incident should identify the fallback drop and original timestamp.

## 7. API Proposal

Suggested endpoints, subject to current route conventions:

### Citizen
- `POST /api/citizen/fallback-drops`
- `GET /api/citizen/fallback-drops/{id}` limited to owner

### Operator
- `GET /api/operator/fallback-drops?status=...`
- `POST /api/operator/fallback-drops/{id}/claim`
- `POST /api/operator/fallback-drops/{id}/callback-attempts`
- `POST /api/operator/fallback-drops/{id}/convert`
- `POST /api/operator/fallback-drops/{id}/close`

Keep state transitions service-owned rather than controller-owned.

## 8. Concurrency

Claiming must be atomic. Two operators must not simultaneously own the same drop. Use a transaction with row locking or a compare-and-set update. Return 409 when another operator has already claimed it.

## 9. Realtime

If Realtime is available, publish privacy-safe events for:

- new fallback drop available;
- claimed;
- converted;
- closed.

Realtime is notification only. The UI must refetch authoritative REST state.

## 10. Reporting / Metrics

Add pilot metrics:

- total busy-state rejections;
- total fallback drops;
- fallback adoption rate;
- median and P95 age to operator claim;
- median and P95 age to first callback attempt;
- conversion-to-incident rate;
- duplicate rate;
- spam/prank rate;
- unresolved fallback backlog.

These must feed the Weekly Pilot Review.

## 11. Security and Privacy

- Do not expose fallback records across citizens.
- Avoid storing unnecessary contact snapshots if canonical Account data can be referenced safely.
- Do not put sensitive information in Realtime payloads.
- Fallback photo evidence must use secure private storage and normalized metadata. Do not expose public file paths.
- Audit operator access and disposition changes.

## 12. Migration / Compatibility

Use additive migrations only. Busy-state fallback is a core Hotline feature, not an optional feature-flagged add-on. Existing successful call routing must continue to work unchanged. The new behavior applies only to the explicit all-operators-busy path.

## 13. Tests

Minimum automated coverage:

- no-operator path returns busy-state capability metadata;
- citizen can create one valid fallback drop;
- validation prevents malformed coordinates/oversized text;
- another citizen cannot read the drop;
- two operators cannot claim simultaneously;
- conversion creates one incident only;
- conversion preserves source/drop reference;
- closed/converted drops cannot be converted again;
- Realtime publish failure does not fail authoritative state change;
- optional photo uploads normalize to WebP and reject invalid images;
- fallback photo metadata does not expose public paths;
- no `call_attempts` row is created for a busy-state fallback drop.

## 14. Acceptance Criteria

Implementation is complete when:

- all-operator-busy calls can result in a durable fallback record without creating a ghost incident;
- no call-attempt record is created for the busy-state fallback path;
- operators have a visible, auditable review queue;
- every fallback record reaches `converted` or `closed` with disposition;
- callback timing is measurable;
- conversion creates a normal incident through one service path;
- fallback drops and photos remain unverified/non-incident evidence until operator conversion;
- pilot reporting can count busy-state rejection, drop, callback, conversion, and backlog metrics.


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
