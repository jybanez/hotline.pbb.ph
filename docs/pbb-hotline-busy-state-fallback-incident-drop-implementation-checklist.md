# PBB Hotline Busy-State Fallback / Incident Drop Implementation Checklist

**Status:** Implementation checklist for agent handoff
**Product:** PBB Hotline
**Related proposal:** `docs/pbb-hotline-busy-state-fallback-incident-drop-development-proposal.md`
**Branch category:** `fallback`
**Suggested branch:** `fallback/busy-incident-drops`

## Product Boundary

- Busy-state fallback is a core Hotline feature, not a feature-flagged add-on.
- Only the explicit all-operators-busy path should offer fallback incident drop creation.
- Do not create a `call_attempts` row when no operator was routed.
- Do not create a normal `incidents` row until an operator converts the fallback drop.
- Fallback drops are unverified citizen-submitted contacts until reviewed by an operator.
- Command does not own fallback review; Operator owns review, callback, conversion, and closure.
- Optional photo evidence is allowed in V1, but must use private storage and normalized metadata. Do not expose public `/storage/...` URLs.

## 1. Branch And Baseline

- Create a clean worktree under `C:\wamp64\www\pbb\hotline-branches\fallback\busy-incident-drops`.
- Create branch `fallback/busy-incident-drops` from latest `origin/main`.
- Read the proposal doc and this checklist before coding.
- Inspect current code paths before implementation:
  - `app\Support\Calls\CallRoutingService.php`
  - `app\Http\Controllers\Api\Citizen\CallAttemptController.php`
  - `resources/js/surfaces/citizenSurface.js`
  - `resources/js/surfaces/operatorSurface.js`
  - `app\Http\Controllers\Api\Operator\DashboardController.php`
  - existing image/message attachment normalization code
  - existing Realtime publish service patterns

## 2. Database And Models

- Add additive migration for `fallback_incident_drops`.
- Required fields:
  - `id`
  - `citizen_id`
  - `status`
  - `reason`
  - `citizen_latitude`
  - `citizen_longitude`
  - `citizen_location_accuracy`
  - `quick_category`
  - `short_description`
  - `callback_contact_snapshot`
  - `claimed_by_operator_id`
  - `converted_incident_id`
  - `closure_disposition`
  - `closure_note`
  - `created_at`
  - `claimed_at`
  - `callback_due_at`
  - `converted_at`
  - `closed_at`
- Do not add `source_call_attempt_id`.
- Add additive migration for `fallback_incident_drop_attachments`.
- Attachment table should store private file identity and normalized image metadata needed to render/review safely.
- Add model(s):
  - `FallbackIncidentDrop`
  - `FallbackIncidentDropAttachment`
  - optional `FallbackIncidentDropHistory` if existing activity logs are insufficient.
- Add relationships to `User`, `Incident`, and operator user where useful.
- Update `database/schema/hotline-schema-mysql.sql`.
- Update migration ledger rows in baseline schema.
- Update `release.json` migration metadata.

## 3. Domain Services

- Add service-owned state transitions. Keep controllers thin.
- Suggested services:
  - `FallbackIncidentDropService`
  - `FallbackIncidentDropAttachmentService`
  - `FallbackIncidentDropConversionService`
- Creation:
  - accept authenticated citizen only
  - validate busy reason as `all_operators_busy`
  - store current location if provided
  - snapshot safe callback/contact context from current user/account fields
  - optionally store normalized WebP photo attachments
- Claim:
  - atomic claim with transaction and row lock or compare-and-set update
  - return 409 when already claimed by another operator
- Close:
  - require controlled disposition and closure note where appropriate
  - block closing converted records
- Convert:
  - create exactly one normal `incidents` row
  - preserve provenance from fallback drop
  - link `converted_incident_id`
  - block repeated conversion
  - carry safe location/contact/photo context into the created incident only through approved incident/media paths
- Realtime:
  - publish privacy-safe notifications only
  - never fail authoritative DB state when Realtime publish fails
  - UI must refetch REST after notifications

## 4. Citizen API

- Update busy call-attempt response:
  - when no operator is available, return structured JSON such as:
    - `ok: false`
    - `reason: no_available_operator`
    - `fallback_available: true`
    - clear user-facing message
  - keep other unavailable reasons honest and non-fallback.
- Add:
  - `POST /api/citizen/fallback-drops`
  - `GET /api/citizen/fallback-drops/{id}`
- Enforce owner-only reads.
- Validate:
  - coordinates and accuracy
  - category length/allowlist if introduced
  - description length
  - photo count, MIME, size, image validity
- Do not create `call_attempts` from these endpoints.
- Rate-limit or restrict repeated active drops per citizen/time window to prevent accidental spam.

## 5. Operator API

- Add:
  - `GET /api/operator/fallback-drops?status=...`
  - `POST /api/operator/fallback-drops/{id}/claim`
  - `POST /api/operator/fallback-drops/{id}/callback-attempts`
  - `POST /api/operator/fallback-drops/{id}/convert`
  - `POST /api/operator/fallback-drops/{id}/close`
- Keep all transition rules in services.
- Serialize review queue rows with:
  - drop age
  - approximate location
  - category/description
  - citizen/contact snapshot
  - attachment metadata safe for operator review
  - status/claim details
  - callback timing fields
- Ensure operators cannot mutate drops in terminal states except through allowed audit-safe paths.

## 6. Citizen UI

- On all-operators-busy structured response:
  - show clear message that operators are busy
  - offer `Leave emergency details for callback`
  - do not imply the emergency is verified
- Build a short, distress-friendly form:
  - location/GPS if available
  - quick category
  - short description
  - optional photo evidence
  - callback/contact consent/context if needed
- On success:
  - show immutable reference/drop ID
  - state that it is awaiting operator review
- Avoid long questionnaire behavior.

## 7. Operator UI

- Add visible Fallback / Callback section to the operator dashboard.
- Show new/unclaimed, claimed, callback pending, converted, and closed as needed.
- Provide actions:
  - claim
  - record callback attempt
  - convert to incident
  - close with disposition
- Conversion should use a dedicated flow/service and should not bypass existing incident ownership conventions.
- Show optional photo evidence in review context using secure internal media access, not public paths.
- Realtime events should refresh the queue but REST remains authoritative.

## 8. Metrics

- Add pilot-countable metrics or queryable fields for:
  - busy rejections
  - fallback drops
  - fallback adoption rate
  - age to claim
  - age to first callback attempt
  - conversion rate
  - duplicate/spam/invalid closure rates
  - unresolved backlog
- If no reporting UI is added in this branch, document the query points and ensure the data exists.

## 9. Tests

- Call attempt all-operators-busy returns structured fallback metadata.
- Other unavailable states do not expose fallback creation as if operators were merely busy.
- Citizen can create one valid fallback drop.
- No `call_attempts` row is created for a fallback drop.
- No `incidents` row is created until conversion.
- Malformed coordinates, oversized text, invalid images, corrupt images, unsupported MIME, and too many photos are rejected.
- Optional photos normalize to authoritative WebP.
- Fallback photo metadata does not expose public paths.
- Another citizen cannot read the drop.
- Two operators cannot claim the same drop.
- Closed and converted drops cannot be converted again.
- Conversion creates exactly one incident and preserves source/drop provenance.
- Realtime publish failure does not fail creation/claim/conversion/closure.
- Operator queue serialization includes safe review context.

## 10. Validation

- Run focused PHP tests for fallback, call attempt flow, media/image normalization, and incident conversion.
- Run relevant JS tests for citizen/operator surfaces.
- Run `npm run build`.
- Run PHP lint on touched PHP files.
- Run `git diff --check`.
- Parse `release.json`.
- Do not build a Hotline installer bundle unless explicitly requested after merge to `main`.

## 11. Reviewer Notes

- Highlight any selected storage path and normalization helper reused for fallback photos.
- Confirm no normal incident is created before operator conversion.
- Confirm no call attempt is created for busy-state fallback.
- Confirm whether a history table was added or existing audit infrastructure was reused.
- Confirm whether Realtime events are notification-only and privacy-safe.

