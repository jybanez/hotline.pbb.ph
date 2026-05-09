# PBB Hotline Beta Feedback Resolution Note

Date: 2026-04-04

Purpose:
- summarize how the recent Beta-team feedback items were resolved
- give a short forwarding note that points back to the updated docs

## Resolved Items

### 1. Operator availability ambiguity

Resolved by defining a canonical operator runtime state family:
- `offline`
- `available`
- `engaged`
- `transferring`
- `reauth_required`

Key outcome:
- new-call routing uses `available`
- reconnect uses assigned operator state of `available` or `engaged` on the same incident

Primary updated docs:
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta Realtime Spec](./pbb-hotline-beta-realtime-spec.md)
- [PBB Hotline Beta Implementation Checklist](./pbb-hotline-beta-implementation-checklist.md)

### 2. Incident lifecycle ambiguity around `New`

Resolved by removing persisted `New` incident status from Beta Phase 1.

Key outcome:
- incident is created only when an operator answers
- first persisted incident status is `Active`

Primary updated docs:
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta Schema Draft](./pbb-hotline-beta-schema-draft.md)
- [OpenAPI Draft](./openapi/pbb-hotline-beta.yaml)

### 3. Workbench restore model after refresh / re-auth

Resolved by defining the workbench as:
- an in-app overlay on `/operator`
- opened by fetching incident payload into the overlay
- restored from retained client state, not from URL mutation

Key outcome:
- browser URL does not need to change
- re-auth restores by re-fetching from retained client state
- refresh may restore from session-scoped client state, otherwise fallback is dashboard

Primary updated docs:
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta Proposal](./pbb-hotline-beta-proposal.md)
- [PBB Hotline Beta API Inventory](./pbb-hotline-beta-api-inventory.md)
- [PBB Hotline Beta App Layer Map](./pbb-hotline-beta-app-layer-map.md)

### 4. Transfer overlap ownership ambiguity

Resolved by separating transport overlap from business ownership.

Key outcome:
- accepted transfer switches incident ownership immediately
- reconnect target flips immediately to the new operator
- old operator becomes read-only immediately
- overlap is for call continuity only

Primary updated docs:
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta Realtime Spec](./pbb-hotline-beta-realtime-spec.md)
- [PBB Hotline Beta API Inventory](./pbb-hotline-beta-api-inventory.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)

### 5. Media artifact model too loose

Resolved by tightening media and call-session modeling.

Key outcomes:
- audio artifacts are produced per peer per call session
- `call_sessions` no longer rely on one session-level `operator_id`
- `call_participants` is the authoritative membership table
- per-peer audio keeps isolated playback possible during transfer overlap and future multi-peer sessions

Primary updated docs:
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta Schema Draft](./pbb-hotline-beta-schema-draft.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [OpenAPI Draft](./openapi/pbb-hotline-beta.yaml)

### 6. Caller green/yellow/red indicator backend truth

Resolved by defining a structured caller availability contract.

Key outcome:
- backend owns:
  - `call_service_ready`
  - `available_operator_count`
  - backend green/yellow/red recommendation
- client may still force local `red` if it cannot reach Hotline backend/session truth at all

Primary updated docs:
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta API Inventory](./pbb-hotline-beta-api-inventory.md)
- [OpenAPI Draft](./openapi/pbb-hotline-beta.yaml)

## Session Baseline Added

The Beta pack now explicitly aligns to:
- [PBB User Session Handling Proposal](C:/wamp64/www/pbb/hub.ph/docs/pbb-user-session-handling-proposal.md)
- [PBB User Session Keepalive Proposal](C:/wamp64/www/pbb/hub.ph/docs/pbb-user-session-keepalive-proposal.md)

Key outcome:
- near-expiry keepalive first
- re-auth modal as fallback
- recommended keepalive endpoint:
  - `GET /api/session/ping`

## Additional Resolved Items

### 7. Reconnect lifecycle missing caller-side cancel contract

Resolved by adding an explicit caller-side cancel endpoint for unanswered reconnects.

Key outcome:
- unanswered reconnects can be cancelled by the caller after ringing has started
- once cancelled, the reconnect call session uses outcome `cancelled_by_caller`
- once answered, normal hang-up rules apply instead

Primary updated docs:
- [PBB Hotline Beta API Inventory](./pbb-hotline-beta-api-inventory.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [OpenAPI Draft](./openapi/pbb-hotline-beta.yaml)

### 8. Implementation checklist lagging the participant-scoped media model

Resolved by rewriting the checklist media pipeline language to match the newer per-peer artifact model.

Key outcome:
- live-call capture is now documented as per-peer audio chunks plus caller video chunks
- the checklist now explicitly follows the `call_participants` and `audio_peer` contract
- transfer-overlap operators are part of the same participant-scoped recording model

Primary updated docs:
- [PBB Hotline Beta Implementation Checklist](./pbb-hotline-beta-implementation-checklist.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)

### 9. Alpha schema reference still showing single-operator call sessions

Resolved by annotating the older schema note so it cannot be mistaken for Beta migration guidance.

Key outcome:
- the old `call_sessions.operator_id -> users.id` relationship is now marked as Alpha-only
- the note explicitly points Beta planning to `call_participants` instead

Primary updated docs:
- [Database Schema Models](./database-schema-models.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Hotline Beta Schema Draft](./pbb-hotline-beta-schema-draft.md)

## Current Position

These nine feedback items are now addressed in the Beta pack.

Recommended forwardable references:
- [PBB Hotline Beta Documentation Pack](./pbb-hotline-beta-doc-pack-index.md)
- [PBB Hotline Beta Handoff Note](./pbb-hotline-beta-handoff-note.md)
