# PBB Hotline Beta Contracts

Date: 2026-04-04

Status: Draft canonical contract pack, with Phase 2 caller-to-citizen compatibility notes

Phase 2 migration note:
- `citizen` is the canonical public-user term.
- Legacy `caller` route names, payload aliases, role values, database columns, media values, and outcome values remain documented only where they are still supported for temporary compatibility or where the document is preserving Phase 1 historical context.

Purpose:
- define Beta's canonical vocabularies
- define Beta's official DTO/data shapes
- give the new team stable payload targets before implementation

## 1. Canonical Code Constants

### Roles
- `citizen`
- `caller` (legacy compatibility)
- `operator`
- `command`
- `admin`

### Alert levels
- `Normal`
- `Elevated`
- `Critical`

Alert-level descriptions should be fixed text in code.

### Incident statuses
- `Active`
- `Deferred`
- `Discarded`
- `Resolved`

Lifecycle note:
- Beta should not persist a `New` incident status in Phase 1
- an incident is created only when an operator answers
- the first persisted incident status is `Active`

### Call session statuses
- `calling`
- `in_progress`
- `ended`

### Call outcomes
- `answered`
- `timed_out`
- `declined_by_operator`
- `cancelled_by_citizen`
- `cancelled_by_caller` (legacy compatibility)
- `ended_by_operator`
- `ended_by_citizen`
- `ended_by_caller` (legacy compatibility)

### Team assignment statuses
- `Assigned`
- `Requested`
- `Accepted`
- `En-route`
- `On-Scene`
- `Completed`
- `Cancelled`

### Team assignment cancellation reason codes
- `mechanical_issue`
- `rerouted_higher_priority`
- `safety_risk`
- `no_contact`
- `resource_unavailable`
- `incorrect_dispatch`
- `other`

### User statuses
- `active`
- `suspended`
- `disabled`
- `pending`

### Operator runtime states
- `offline`
- `available`
- `engaged`
- `transferring`
- `reauth_required`

Notes:
- this is a runtime/business state family, not an incident status family
- Phase 1 should derive it from session, Realtime readiness, and current incident engagement
- Phase 1 should not introduce a separate persisted operator-presence table just to store this

## 2. Shared User DTO

Baseline shared user shape:

```json
{
  "id": 1,
  "name": "Maria Santos",
  "avatar": "/storage/avatars/1.jpg",
  "mobile": "09171234567",
  "email": "maria@example.pbb.ph",
  "role": "caller",
  "status": "active",
  "last_login_at": "2026-04-04T09:00:00+08:00",
  "created_at": "2026-04-01T09:00:00+08:00",
  "updated_at": "2026-04-04T09:00:00+08:00"
}
```

Notes:
- Beta keeps users simple in Phase 1
- no address field
- no username field
- email is the login identifier

## 3. Caller Summary DTO

Embedded inside incident/workbench payloads when needed:

```json
{
  "id": 18,
  "name": "Maria Santos",
  "avatar": "/storage/avatars/18.jpg",
  "mobile": "09171234567"
}
```

## 4. Caller Availability DTO

Used by caller home payloads to drive the green/yellow/red indicator.

```json
{
  "status": "green",
  "service_reachable": true,
  "call_service_ready": true,
  "available_operator_count": 2
}
```

Allowed `status` values:
- `green`
- `yellow`
- `red`

Rules:
- `green`
  - Hotline service is reachable
  - call service is ready
  - at least one operator is in runtime state `available`
- `yellow`
  - Hotline service is reachable
  - call service is ready
  - zero operators are in runtime state `available`
- `red`
  - Hotline service is not reachable enough to start a call, or
  - backend indicates call service is not ready

Important note:
- caller Device Primer warnings do not directly change this color unless they block calling locally
- geolocation warning must not force `yellow` or `red`
- the client may locally force `red` when it cannot reach Hotline backend/session truth at all
## 5. Operator Summary DTO

Embedded inside incident/workbench payloads when needed:

```json
{
  "id": 7,
  "name": "Operator Seven",
  "level": "Level 2",
  "avatar": "/storage/avatars/7.jpg"
}
```

## 6. Incident List Item DTO

Used for operator dashboard cards:

```json
{
  "id": 16,
  "display_id": "000016",
  "caller_avatar": "/storage/avatars/18.jpg",
  "actual_caller_name": "Maria Santos",
  "status": "Active",
  "created_at": "2026-04-04T08:30:00+08:00"
}
```

Used for caller recent-history list:

```json
{
  "display_id": "000016",
  "status": "Resolved",
  "created_at": "2026-04-04T08:30:00+08:00"
}
```

## 7. Incident Workbench DTO

This is the full incident view payload, not a list payload.

```json
{
  "id": 16,
  "display_id": "000016",
  "caller_id": 18,
  "caller": {
    "id": 18,
    "name": "Maria Santos",
    "avatar": "/storage/avatars/18.jpg",
    "mobile": "09171234567"
  },
  "actual_caller_name": "Maria Santos",
  "actual_caller_relationship": "self",
  "latitude": 10.3306796,
  "longitude": 123.827963,
  "location": "Guadalupe, Cebu City, Central Visayas, 6000, Philippines",
  "location_road": "Sample Road",
  "location_suburb": "Guadalupe",
  "location_barangay": "Guadalupe",
  "location_citymunicipality": "Cebu City",
  "location_country": "Philippines",
  "operator_id": 7,
  "operator": {
    "id": 7,
    "name": "Operator Seven",
    "level": "Level 2",
    "avatar": "/storage/avatars/7.jpg"
  },
  "status": "Active",
  "alert_level": "Critical",
  "called_at": "2026-04-04T08:30:12+08:00",
  "resolved_at": null,
  "other_details": "",
  "created_at": "2026-04-04T08:30:12+08:00",
  "updated_at": "2026-04-04T08:40:00+08:00",
  "call_history": [],
  "transfer_history": [],
  "messages": [],
  "media": [],
  "incident_type_details": [],
  "incident_resources_needed": [],
  "team_assignments": []
}
```

Notes:
- SITREP is not part of the incident DTO family
- this payload should support operator workbench and caller incident view
- location may be partial when geocoding is partial

## 8. Call Attempt DTO

Represents a new-call attempt before incident creation.

```json
{
  "id": 401,
  "caller_id": 18,
  "incident_id": null,
  "answered_by_operator_id": null,
  "status": "ended",
  "outcome": "timed_out",
  "caller_latitude": 10.3306796,
  "caller_longitude": 123.827963,
  "started_at": "2026-04-04T08:30:00+08:00",
  "ended_at": "2026-04-04T08:30:20+08:00",
  "created_at": "2026-04-04T08:30:00+08:00",
  "updated_at": "2026-04-04T08:30:20+08:00"
}
```

Rules:
- create only when a real new-call routing attempt begins
- no record for yellow/red blocked home-screen call attempts
- if eventually answered, `incident_id` can be filled after conversion

## 9. Call Attempt Operator Attempt DTO

Child record per operator ring attempt under a call attempt.

```json
{
  "id": 901,
  "call_attempt_id": 401,
  "operator_id": 7,
  "status": "ended",
  "outcome": "timed_out",
  "started_at": "2026-04-04T08:30:00+08:00",
  "answered_at": null,
  "ended_at": "2026-04-04T08:30:10+08:00",
  "created_at": "2026-04-04T08:30:00+08:00"
}
```

## 10. Call Session DTO

Represents an incident-bound call. Every reconnect is a new call session.

```json
{
  "id": 31,
  "incident_id": 16,
  "caller_id": 18,
  "status": "ended",
  "outcome": "ended_by_operator",
  "started_at": "2026-04-04T08:31:00+08:00",
  "answered_at": "2026-04-04T08:31:03+08:00",
  "ended_at": "2026-04-04T08:42:00+08:00",
  "created_at": "2026-04-04T08:31:00+08:00",
  "updated_at": "2026-04-04T08:42:00+08:00",
  "participants": [
    {
      "id": 801,
      "call_session_id": 31,
      "user_id": 18,
      "participant_role": "caller",
      "joined_at": "2026-04-04T08:31:00+08:00",
      "left_at": "2026-04-04T08:42:00+08:00"
    },
    {
      "id": 802,
      "call_session_id": 31,
      "user_id": 7,
      "participant_role": "operator",
      "joined_at": "2026-04-04T08:31:00+08:00",
      "left_at": "2026-04-04T08:36:00+08:00"
    },
    {
      "id": 803,
      "call_session_id": 31,
      "user_id": 9,
      "participant_role": "operator",
      "joined_at": "2026-04-04T08:35:40+08:00",
      "left_at": "2026-04-04T08:42:00+08:00"
    }
  ]
}
```

Minimal call-history presentation fields:
- started
- ended
- duration

Notes:
- remove session-level `operator_id` from the call session contract
- use `participants[]` to describe who actually joined that session
- this is required for transfer overlap and future multi-peer sessions
- if an unanswered reconnect call session is cancelled by the citizen, use outcome `cancelled_by_citizen`

## 11. Call Participant DTO

Represents one user who joined one call session.

```json
{
  "id": 801,
  "call_session_id": 31,
  "user_id": 18,
  "participant_role": "caller",
  "joined_at": "2026-04-04T08:31:00+08:00",
  "left_at": "2026-04-04T08:42:00+08:00"
}
```

Allowed `participant_role` values in Phase 1:
- `caller`
- `operator`

Notes:
- one `call_session` may have multiple operator participants
- `participant_role` describes the user's role inside that call session, not the user's global account role

## 12. Transfer DTO

```json
{
  "id": 15,
  "incident_id": 16,
  "from_operator_id": 7,
  "to_operator_id": 9,
  "reason": "Supervisor handoff",
  "status": "accepted",
  "requested_at": "2026-04-04T08:35:00+08:00",
  "accepted_at": "2026-04-04T08:35:08+08:00",
  "rejected_at": null,
  "cancelled_at": null,
  "completed_at": "2026-04-04T08:35:10+08:00"
}
```

Transfer-history list presentation:
- target operator
- reason

Full details may be shown in a modal.

Transfer ownership note:
- once a transfer is accepted, the new operator becomes the sole incident owner immediately
- reconnect target flips immediately to the new operator
- the old operator may remain in the live call briefly, but only as read-only participant

## 11. Message DTO

```json
{
  "id": 210,
  "incident_id": 16,
  "sender_id": 18,
  "sender_role": "caller",
  "sender_name": "Maria Santos",
  "sender_avatar": "/storage/avatars/18.jpg",
  "body": "Help us please.",
  "type": "message",
  "attachments": [],
  "created_at": "2026-04-04T08:33:10+08:00"
}
```

Allowed `type` values:
- `message`
- `system`

Notes:
- use `created_at`, not `date_created`
- shape should satisfy Helper chat thread contract needs

## 13. Message Attachment DTO

Recommended separate table and separate DTO:

```json
{
  "id": 700,
  "message_id": 210,
  "type": "image",
  "mime_type": "image/jpeg",
  "original_filename": "photo.jpg",
  "stored_path": "/storage/incidents/16/messages/700.jpg",
  "file_size": 245122,
  "thumbnail_path": "/storage/incidents/16/messages/thumbs/700.jpg",
  "uploaded_by": 18,
  "created_at": "2026-04-04T08:33:12+08:00"
}
```

Do not add `incident_id` by default; it is implied through `message_id`.

## 14. Media DTO

Post-call merged media record:

```json
{
  "id": 520,
  "incident_id": 16,
  "call_session_id": 31,
  "type": "audio_peer",
  "peer_user_id": 18,
  "peer_role": "caller",
  "peer_label": "Caller",
  "path": "/storage/incidents/16/media/audio-520.webm",
  "duration_seconds": 660,
  "metadata": {
    "track_kind": "audio",
    "merged": true
  },
  "created_at": "2026-04-04T08:42:00+08:00",
  "available_at": "2026-04-04T08:42:20+08:00"
}
```

Rules:
- media becomes visible/playable only after merge completes
- operator has audio + video access
- caller has video-only access
- playback is incident-level and combined chronologically across sessions
- audio artifacts are produced per peer per call session
- this is required so playback can isolate each peer audio track
- Phase 1 audio must not collapse all operator voices into one mixed `operator_audio` artifact
- caller video may still remain a separate video artifact when present

Recommended media `type` values for Phase 1:
- `audio_peer`
- `caller_video`

Peer audio notes:
- one completed call session may produce multiple `audio_peer` artifacts
- each `audio_peer` artifact represents one final merged audio file for one peer in one call session
- this supports:
  - caller audio
  - assigned operator audio
  - transfer-overlap operator audio
  - future additional participant audio

## 15. Team Category DTO

```json
{
  "id": 1,
  "name": "Medical Support",
  "description": "Medical and rescue teams",
  "sort_order": 10,
  "created_at": "2026-04-01T09:00:00+08:00"
}
```

## 16. Team DTO

```json
{
  "id": 3,
  "team_category_id": 1,
  "category": "Medical Support",
  "name": "Team Alpha",
  "status": "active",
  "created_at": "2026-04-01T09:00:00+08:00",
  "resources": []
}
```

Embedded team info should be present on assignment cards:
- team name
- team category/type
- team resources

## 17. Team Resource Inventory DTO

```json
{
  "id": 80,
  "team_id": 3,
  "resource_type_id": 5,
  "resource_name": "Ambulance",
  "quantity_available": 2,
  "created_at": "2026-04-01T09:00:00+08:00"
}
```

## 18. Team Assignment DTO

```json
{
  "id": 91,
  "incident_id": 16,
  "team_id": 3,
  "team": {
    "id": 3,
    "team_category_id": 1,
    "category": "Medical Support",
    "name": "Team Alpha",
    "status": "active",
    "resources": []
  },
  "assigned_by_operator_id": 7,
  "status": "En-route",
  "contact_person": "Juan Dela Cruz",
  "cancelled_from_status": null,
  "cancel_reason_code": null,
  "cancelled_by_operator_id": null,
  "allocated_resources": [
    {
      "resource_type_id": 5,
      "quantity_allocated": 1
    }
  ],
  "assigned_at": "2026-04-04T08:35:00+08:00",
  "accepted_at": "2026-04-04T08:36:00+08:00",
  "enroute_at": "2026-04-04T08:38:00+08:00",
  "arrived_at": null,
  "completed_at": null,
  "cancelled_at": null,
  "created_at": "2026-04-04T08:35:00+08:00",
  "updated_at": "2026-04-04T08:38:00+08:00"
}
```

Rules:
- a team can only be assigned once per incident
- if cancelled while still `Assigned`, delete the record instead of preserving a cancelled row
- `contact_person` required for:
  - `Accepted`
  - `En-route`
  - `On-Scene`
  - `Completed`

## 18. Resource Type DTO

```json
{
  "id": 5,
  "category_id": 2,
  "category": {
    "id": 2,
    "name": "Vehicle",
    "description": "Vehicle-based response assets",
    "sort_order": 10
  },
  "name": "Ambulance",
  "unit_label": "unit",
  "created_at": "2026-04-01T09:00:00+08:00"
}
```

## 19. Incident Category DTO

```json
{
  "id": 1,
  "name": "Medical & Rescue",
  "description": "Medical and rescue incident category",
  "sort_order": 10,
  "created_at": "2026-04-01T09:00:00+08:00"
}
```

## 20. Incident Type DTO

```json
{
  "id": 11,
  "incident_category_id": 1,
  "category_name": "Medical & Rescue",
  "name": "Medical Emergency",
  "description": "Urgent medical incident",
  "default_required_resources": [],
  "created_at": "2026-04-01T09:00:00+08:00"
}
```

## 21. Incident Type Field Definition DTO

```json
{
  "id": 50,
  "incident_type_id": 11,
  "field_key": "patients",
  "field_label": "Patients",
  "input_type": "number",
  "options": [],
  "default_value": 1,
  "placeholder": "Enter patient count",
  "unit": "persons",
  "is_required": true,
  "sort_order": 20,
  "min": 1,
  "max": 50,
  "step": 1,
  "created_at": "2026-04-01T09:00:00+08:00"
}
```

## 22. Incident Type Detail DTO

Represents a reported field value under an incident type.

```json
{
  "id": 600,
  "incident_id": 16,
  "incident_type_id": 11,
  "field_id": 50,
  "field_label": "Patients",
  "field_key": "patients",
  "field_value": 2,
  "input_type": "number",
  "options": [],
  "unit": "persons",
  "placeholder": "Enter patient count",
  "is_required": true,
  "sort_order": 20,
  "created_at": "2026-04-04T08:32:00+08:00",
  "updated_at": "2026-04-04T08:32:10+08:00"
}
```

Notes:
- `field_id` is required
- grouped rendering should follow Helper incident type component expectations
- Hotline must provide:
  - incident categories
  - incident types
  - resource types

## 23. Incident Resource Needed DTO

Derived from incident type defaults plus reported details.

```json
{
  "resource_type_id": 5,
  "resource_name": "Ambulance",
  "quantity_required": 1,
  "notes": null
}
```

## 24. Activity Log DTO

```json
{
  "id": 1001,
  "incident_id": 16,
  "action_type": "team_assignment_updated",
  "message": "Team Alpha marked En-route.",
  "actor": "Operator Seven",
  "created_at": "2026-04-04T08:38:00+08:00"
}
```

Operator dashboard log should be filtered to:
- operator's own actions
- important events affecting that operator's incidents

## 25. Settings Contract

Use a simple key-value settings table.

Expected Phase 1 keys:
- `call_hold_seconds`
- `call_timeout_seconds`
- `reconnect_timeout_seconds`
- `alert_level`
- `alert_voice`
- `audio_graph_style`

## 26. Phase Boundary Note

This contract pack intentionally does not formalize a SITREP DTO yet.

Reason:
- SITREP is now a later phase after Phase 1 incident-reporting stabilization
- Phase 1 should stabilize caller/operator/admin contracts first
