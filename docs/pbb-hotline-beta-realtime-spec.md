# PBB Hotline Beta Realtime Spec

Date: 2026-05-10

Status: Draft integration spec, updated for caller-to-citizen migration

References:
- [PBB Hotline Beta System Spec](./pbb-hotline-beta-spec.md)
- [PBB Hotline Beta Contracts](./pbb-hotline-beta-contracts.md)
- [PBB Realtime Room And Presence Spec](C:/wamp64/www/pbb/_tmp_helpers_live/docs/pbb-realtime-room-and-presence-spec.md)
- [Call And Conference Tutorial](https://realtime.pbb.ph/sdk-docs/tutorials/conference)

Purpose:
- define how Hotline Beta should use Realtime
- separate transport/runtime responsibilities from Hotline business rules
- make room naming, admissions, presence, and signaling concrete enough for implementation

## 1. Responsibility Split

### Hotline backend owns
- user authentication
- role authorization
- incident ownership checks
- operator runtime-state checks
- persistence of call-attempt, incident, call-session, and message business records
- reconnect eligibility checks
- transfer policy
- persistence of incidents, call attempts, call sessions, messages, and media metadata
- Realtime admission issuance through backend-side integration

### Realtime owns
- websocket lifecycle
- room membership
- presence fanout
- chat publish/subscribe transport
- attachment chunk transport
- call signaling transport
- conference-capable signaling/runtime support

### Frontend SDK owns
- connect/reconnect
- join/leave rooms
- publish/receive chat
- publish/receive call signaling
- targeted conference signaling per participant

### Operator frontend additionally owns in Beta
- responding to citizen availability discovery when locally eligible
- claiming the call request as the first available operator
- making Hotline backend requests that persist call-attempt and answer/decline state
- publishing the authoritative Realtime call-state events after backend persistence succeeds

### Citizen frontend additionally owns in Beta
- broadcasting operator availability discovery
- requesting a selected available operator through Realtime
- reacting to authoritative operator-published call-state events

Important Beta constraint:
- after the citizen starts a call request, call-state progression should be driven by Realtime events
- citizen-side polling must not be the normal progression path
- citizen-side server traffic should stay minimized because citizens may have fragile Wi-Fi connectivity
- persistence requests should be made from the operator side whenever feasible

## 2. Room Taxonomy

### Incident chat room

Purpose:
- transport chat messages and attachment metadata/events tied to an incident during live call

Suggested room name:
- `chat.thread.incident.{incidentId}`

### Call session room

Purpose:
- live call signaling for a single call session

Suggested room name:
- `call.session.{callSessionId}`

### Operator dashboard presence room

Purpose:
- optional operator-availability presence coordination within the local operator surface

Suggested room name:
- `presence.workspace.operator`

Note:
- this is optional in Beta
- availability may still be derived from local app/session state first

## 3. Admission Model

Frontend must never self-issue trust.

Admission is always backend-issued.

### Citizen admission endpoint
- canonical: `POST /api/realtime/admission/citizen`
- legacy compatibility: `POST /api/realtime/admission/caller`

Use when:
- citizen enters live call
- citizen enters incident chat during live call

### Operator admission endpoint
- `POST /api/realtime/admission/operator`

Use when:
- operator accepts a live call
- operator joins live incident chat
- operator participates in transfer overlap conference

## 4. Capability Model

Suggested logical capability buckets:
- `room.join`
- `presence.subscribe`
- `presence.publish`
- `chat.subscribe`
- `chat.publish`
- `call.signal`
- `attachments.publish`
- `attachments.receive`

### Citizen capability guidance
- join current incident chat room
- join current call session room
- publish/receive chat during live call
- publish attachments during live call
- publish/receive call signaling

### Operator capability guidance
- join current incident chat room
- join current call session room
- publish/receive chat during live call
- publish/receive attachments
- publish/receive call signaling

Transfer overlap:
- both old and new operator may temporarily share call signaling participation

## 5. Presence Model

Presence should be used narrowly.

Recommended Phase 1 uses:
- current participants in a live call
- optional operator dashboard availability hints
- operator availability discovery for new-call claiming

Do not use Realtime presence as the only business truth for operator availability.

Operator availability truth still belongs to Hotline business logic:
- canonical operator runtime state
- active operator engagement in an incident
- transfer overlap state
- re-auth blocking state
- incident assignment rules

Canonical runtime states:
- `offline`
- `available`
- `engaged`
- `transferring`
- `reauth_required`

Availability response rule in Beta:
- `citizen.operator.available.response` must come from the operator side
- the first eligible operator to respond claims the citizen request
- eligibility is evaluated locally on the operator side from:
  - logged in
  - online
  - not currently working on any incident
  - not currently on an active call

## 6. New Call Flow

1. citizen passes Device Primer + green home-state gating
2. citizen broadcasts `citizen.operator.available.request`
3. first eligible operator responds `citizen.operator.available.response`
4. citizen issues `citizen.call.request` targeting that operator
5. target operator receives the request and calls Hotline backend to create:
   - `call_attempt`
   - `call_attempt_operator_attempt`
6. after persistence succeeds, operator publishes `citizen.call.ringing`
7. citizen receives `citizen.call.ringing` and shows the ringing / calling modal
8. operator shows the incoming-call modal for the same attempt
9. if citizen cancels before answer:
   - citizen publishes `citizen.call.cancel`
   - operator persists the cancellation outcome in Hotline backend
   - operator publishes `citizen.call.cancelled`
10. if operator declines:
   - operator persists declined outcome in Hotline backend
   - operator publishes `citizen.call.declined`
11. if operator answers:
   - operator persists answered outcome
   - operator creates:
     - incident
     - first `call_session`
   - operator publishes `citizen.call.answered`
12. citizen receives `citizen.call.answered`, renders active call UI, and requests admission for:
   - `chat.thread.incident.{incidentId}`
   - `call.session.{callSessionId}`
13. operator opens workbench and requests admission for the same rooms

Critical rule:
- new-call persistence is operator-driven in Beta
- citizen does not directly create or mutate Hotline business records during the new-call handshake
- Realtime is the citizen-facing progression path after the request is sent

Compatibility rule:
- during the caller-to-citizen refactor, Hotline clients accept both `citizen.*` and legacy `caller.*` call-state events
- Hotline-owned publishers should emit `citizen.*` events
- payloads should include canonical `citizen_id` / `citizen_name` fields and temporary legacy `caller_id` / `caller_name` aliases where that helps deployed clients

## 7. Reconnect Flow

1. citizen presses `Resume Call`
2. citizen targets the currently assigned operator through Realtime
3. assigned operator verifies local eligibility:
   - in runtime state `available`, or
   - in runtime state `engaged` on this same incident
4. if not eligible:
   - operator publishes a blocked/failed reconnect result
   - create no reconnect-attempt record
5. if eligible:
   - operator calls Hotline backend to create new `call_session`
   - operator publishes reconnect ringing state
6. when operator answers:
   - operator publishes `citizen.call.answered`
   - both sides keep using the same incident chat room
   - both sides join the new `call.session.{callSessionId}` room

## 8. Chat Model

Chat business rule:
- chat composer enabled only during live call
- history remains readable after call

Realtime implication:
- chat transport may still use the incident chat room
- UI must prevent send outside live call
- server should also validate live-call eligibility for message creation

## 9. Attachment Model

Allowed live-call chat attachments:
- photos
- videos

No documents in Phase 1.

Transport model:
- use Realtime attachment chunk transport where suitable
- Hotline persists final attachment metadata into `message_attachments`
- Realtime is transport only, not long-term business storage

## 10. Call Signaling Model

Transport events should remain distinct from business statuses.

Business call statuses:
- `calling`
- `in_progress`
- `ended`

Business outcomes:
- `answered`
- `timed_out`
- `declined_by_operator`
- `cancelled_by_citizen`
- `cancelled_by_caller` (legacy compatibility)
- `ended_by_operator`
- `ended_by_citizen`
- `ended_by_caller` (legacy compatibility)

Signaling events may include:
- ring/start
- answer
- offer
- answer-sdp
- ice-candidate
- hangup

Do not collapse transport events into business status fields.

## 11. Transfer Overlap Ownership

Accepted transfer must separate transport overlap from business ownership.

Business rule:
- the moment transfer is accepted, incident ownership switches to the new operator
- reconnect target switches immediately to the new operator
- old operator may remain in the conference briefly, but only as read-only participant
- only the new operator may mutate incident business data during overlap

Realtime implication:
- both operators may temporarily remain admitted to the active call session room
- workbench mutation requests from the old operator must be rejected after acceptance
- presence overlap must not be interpreted as shared business ownership

## 11. Conference Model

Realtime already supports small-group mesh conference behavior.

Hotline Beta should use that only for:
- transfer overlap handoff in Phase 1

Rules:
- accepted transfer can become a temporary small conference
- assigned operator switches to the new operator immediately
- old operator may remain briefly for handoff
- citizen may hear both operators during overlap

Future:
- keep channel/join model ready for later invite function
- invite function is later than SITREP phase

## 12. Media Capture Boundary

Citizen-side resource consumption should stay minimized.

Current Beta capture strategy:
- operator terminal streams operator audio and citizen audio/video toward Hotline server
- transport may use Realtime websocket/signaling path as appropriate
- media chunks are saved in near real time
- on call end, server merges chunks into final media files
- final media records become available only after merge completion

Artifact rule:
- final audio artifacts must be created per peer per call session
- do not collapse multiple operator voices into one session-level operator audio file
- this is required for isolated playback during transfer overlap and future multi-peer participation
- citizen video may remain a separate citizen video artifact when present

## 13. Settings Live-Update Events

Important runtime settings should propagate live through Realtime when appropriate.

Examples:
- alert level changed
- call timeout changed
- reconnect timeout changed
- alert voice changed
- audio graph style changed

Client rule:
- apply immediately when safe
- defer to next relevant action when applying mid-call would be disruptive

## 14. Suggested Event Families

Suggested logical event families for Beta:
- `hotline.settings.updated`
- `hotline.alert_level.changed`
- `citizen.operator.available.request`
- `citizen.operator.available.response`
- `citizen.call.request`
- `citizen.call.ringing`
- `citizen.call.cancel`
- `citizen.call.cancelled`
- `citizen.call.declined`
- `citizen.call.answered`
- `citizen.call.ready`
- `citizen.location.updated`
- `citizen.reconnect.request`
- `citizen.reconnect.ringing`
- `citizen.reconnect.failed`
- `hotline.transfer.requested`
- `hotline.transfer.accepted`
- `hotline.transfer.rejected`
- `hotline.media.processing`
- `hotline.media.available`

These names are guidance for app-owned event normalization, not a claim about gateway-owned built-in event names.

Legacy `caller.*` equivalents are accepted temporarily while deployed Hotline clients finish migrating. Realtime service examples and fixtures should prefer `citizen.*` once this Hotline compatibility layer is deployed.

### Call Event Payload Guidance

#### `citizen.operator.available.request`
```json
{
  "citizen_id": 18,
  "caller_id": 18
}
```

#### `citizen.operator.available.response`
```json
{
  "citizen_id": 18,
  "caller_id": 18,
  "operator_id": 7,
  "responded_at": "2026-04-12T09:00:00+08:00"
}
```

#### `citizen.call.request`
```json
{
  "citizen_id": 18,
  "caller_id": 18,
  "operator_id": 7
}
```

#### `citizen.call.ringing`
```json
{
  "call_attempt_id": 401,
  "call_attempt_operator_attempt_id": 990,
  "citizen_id": 18,
  "caller_id": 18,
  "operator_id": 7,
  "requested_at": "2026-04-12T09:00:04+08:00"
}
```

#### `citizen.call.cancel`
```json
{
  "call_attempt_id": 401,
  "call_attempt_operator_attempt_id": 990,
  "citizen_id": 18,
  "caller_id": 18,
  "operator_id": 7,
  "cancelled_at": "2026-04-12T09:00:07+08:00"
}
```

#### `citizen.call.cancelled`
```json
{
  "call_attempt_id": 401,
  "call_attempt_operator_attempt_id": 990,
  "citizen_id": 18,
  "caller_id": 18,
  "operator_id": 7,
  "outcome": "cancelled_by_citizen",
  "ended_at": "2026-04-12T09:00:08+08:00"
}
```

#### `citizen.call.declined`
```json
{
  "call_attempt_id": 401,
  "call_attempt_operator_attempt_id": 990,
  "citizen_id": 18,
  "caller_id": 18,
  "operator_id": 7,
  "outcome": "declined_by_operator",
  "ended_at": "2026-04-12T09:00:10+08:00"
}
```

#### `citizen.call.answered`
```json
{
  "call_attempt_id": 401,
  "call_attempt_operator_attempt_id": 990,
  "incident_id": 16,
  "chat_room": "chat.thread.incident.16",
  "call_room": "call.session.31",
  "call_session_id": 31,
  "citizen_id": 18,
  "caller_id": 18,
  "operator_id": 7,
  "answered_at": "2026-04-12T09:00:12+08:00"
}
```

Citizen and operator should treat operator-published terminal call events as authoritative because those events are emitted only after Hotline backend persistence succeeds.

## 15. Error / Recovery Notes

If Realtime local runtime is degraded:
- citizen/operator app should surface clear failure state
- no browser-side trust minting fallback is allowed
- Hotline should preserve business-state correctness even when live transport is interrupted

If session expires:
- app opens re-auth modal
- successful re-auth should restore current context and re-establish Realtime admissions as needed

## 16. Implementation Notes

Recommended minimum Beta implementation pieces:
- backend admission endpoints
- room-name builders
- capability builders by role + context
- client-side room join coordinator
- call signaling adapter
- incident chat adapter
- settings-update listener
- transfer overlap conference adapter
