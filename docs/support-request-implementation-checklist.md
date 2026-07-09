# Support Request Implementation Checklist

This checklist tracks the Hotline-side implementation for explicit Support Requests sent through Relay.

Related contract proposal:

- `docs/support-request-relay-contract-proposal.md`

## Product Boundary

- SITREP informs; Support Request tasks.
- Do not auto-create requests from SITREP needs, gaps, resource tables, or confidence notes.
- A Command user must explicitly create and submit a Support Request.
- Hotline submits `support.request`.
- Support owns triage, assignment, and lifecycle updates.
- Relay only transports, routes, retries, and records delivery.

## 1. Database, Models, And History

- Add `support_requests` table for local request records.
- Add `support_request_histories` table for lifecycle and delivery events.
- Track local request identity:
  - `local_request_id`
  - `correlation_id`
  - `support_request_id`
  - `relay_message_id`
- Track request content:
  - status
  - urgency
  - requested capability or assistance
  - quantity and unit
  - staging notes
  - command notes
  - requester identity
  - source hub
  - linked SITREP
  - linked evidence row
  - linked incidents
- Store outbound delivery status:
  - `pending`
  - `relay_accepted`
  - `failed`
- Store inbound Relay message IDs or update IDs for idempotency.
- Preserve rejected, unknown, or unactionable authenticated updates for debugging.

## 2. Command UI Request Support Form

- Add explicit `Request Support` action only on requestable SITREP operational resource context.
- Requestable in the current Command UI:
  - resource supply/open-needs gap;
  - evidence row kind `resource_need`;
  - valid canonical `resource_type_id` from Hotline `resource_types`.
- Not requestable by default:
  - route/access gaps by themselves;
  - population/data-confidence gaps;
  - category-only resource summaries;
  - free-text-only evidence;
  - logistics or staging notes without a canonical resource;
  - rescue/access support gaps unless represented by a canonical resource evidence row;
  - counting/data-quality notes;
  - historical context;
  - resolved/discarded context.
- Rationale:
  - road/access and population gaps provide context;
  - resource evidence rows create taskable requests;
  - route/population context is linked through the selected SITREP evidence and selected incident IDs.
- Use Helper UI modal/form where available.
- Prefill from selected SITREP gap/evidence row.
- Allow Command users to edit prefilled request details before submit.
- Required fields:
  - requested assistance/capability, derived from canonical resource type;
  - urgency;
  - quantity/unit;
  - at least one justification code;
  - selected incident IDs from the selected resource evidence row.
- Optional fields:
  - command notes.
- The current UI no longer asks for staging/access/logistics notes as a primary field; route/access context should already be represented in the linked SITREP evidence when relevant.

## 3. Outbound Relay Submission

- Submit Relay message type `support.request`.
- Use `source_system` from Hotline settings, default likely `hotline.command`.
- Use canonical Relay `targets[]` from local `/hub.json` uplinks.
- Target system default should align with Support, likely `support.dispatch`.
- Payload should include compact request context:
  - request identity
  - source hub
  - requester
  - SITREP reference
  - gap/evidence row reference
  - incident references
  - selected incident IDs
  - canonical resource block
  - requested capability/assistance
  - urgency
  - justification codes
  - notes
- Do not embed full SITREP JSON.
- Persist locally before Relay submission.
- Update local status to `requested` after local creation.
- Update outbound delivery status to `relay_accepted` on Relay `201` or accepted response.
- Record Relay message ID.
- Record Relay rejection/failure details separately from Support rejection.

## 4. Inbound Relay Update Endpoint

- Add internal endpoint:

```text
POST /api/internal/relay/support-request-updates
```

- Use Relay handler auth, not browser/session auth.
- Accept only `support.request.*` message types.
- Validate:
  - schema version
  - `local_request_id` or `correlation_id`
  - status
  - update time
  - update/message ID
- Find request by `local_request_id` or `correlation_id`.
- Idempotently ignore duplicate Relay message IDs or update IDs.
- Append status/delivery history.
- Update current request status when the inbound update is valid.
- Return clear accepted/rejected response to Relay.
- Preserve authenticated but unknown updates for debugging.
  - Current first-pass schema cannot persist unknown updates because `support_request_histories` requires a known `support_request_id`; Hotline should log and return a clear unknown-request response until a rejected-message table exists.

## 5. Tokens And Settings

- Add settings for Support Request Relay source system if not already covered.
- Add settings for Support Request target systems if not already covered.
- Add inbound Relay handler token setting if Relay requires a distinct token.
- Reuse existing Relay URL and outbound token patterns where appropriate.
- Keep outbound app token separate from inbound handler token if Relay contract requires it.
- Do not invent auth headers; implement Relay's handler delivery header contract.

## 6. Lifecycle Display

- Show current request status in Command.
- Show status and delivery history in Command.
- Display linked SITREP/evidence/incident context.
- Local-owned states:
  - `draft`
  - `requested`
  - `relay_accepted`
  - `cancelled`
  - `failed`
- Support-owned states:
  - `received`
  - `under_review`
  - `accepted`
  - `rejected`
  - `assigned`
  - `en_route`
  - `fulfilled`
  - `closed`
- Display Relay submission failure as delivery state, not Support rejection.

## 7. Media Evidence Integration

- Keep media files out of `support.request` Relay payloads.
- Use linked SITREP context, selected incident IDs, and canonical resource evidence to identify related media refs.
- Treat `source_snapshot.rollup.media_refs[]` as the media discovery list.
- Do not expose public `/storage/...` URLs as the integration contract.
- Use the Hotline-owned media SDK/API for upstream media access.
- Validate access with hub-to-hub / HQ token trust through the source Hotline hub.
- Let upstream apps cache authorized media locally; Hotline should not dictate their cache path, retention, or UI.
- Support Request UI may show media availability later, but media drill-down remains optional evidence context and must not block request submission.

## 8. Tests And Browser Smoke

- Feature/unit tests for request creation and validation.
- Tests for explicit requestability rules.
- Tests proving no auto-request is created from passive SITREP gaps/needs.
- Tests for outbound Relay envelope and compact payload.
- Tests for local persistence before Relay submission.
- Tests for Relay accepted and failed delivery states.
- Tests for inbound update auth.
- Tests for inbound update idempotency.
- Tests for unknown request update handling.
- Tests for status history append/update behavior.
- Browser smoke:
  - Command opens Request Support form from requestable Gaps evidence.
  - Prefill works from selected SITREP/evidence row.
  - Non-requestable Data Confidence rows do not show request actions.
  - Submit persists local request.
  - Relay success/failure displays correctly.
  - Lifecycle updates appear after inbound Relay update.

## Bundle Boundary

- No Hotline installer bundle should be built from feature branches.
- Bundle handoff to Kit happens only from clean `main` after merge and explicit approval.
