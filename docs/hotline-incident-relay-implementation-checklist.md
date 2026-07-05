# Hotline Incident Relay Implementation Checklist

Status: draft  
Owner: PBB Hotline Beta  
Consumers: PBB Utility/Vena first, future PBB operational apps later  
Transport: PBB Relay  

## Purpose

Export toned-down live Hotline incident reports upward through Relay so Utility/Vena can map incidents beside utility assets, teams, and missions.

This is not SITREP relay and not Support Request relay.

- SITREP relay summarizes operational posture.
- Support Request relay creates an explicit tasking request.
- Incident relay exports current known state for individual Hotline incidents.

## Boundary

Hotline owns:

- Message family and payload envelope for Hotline-origin incident exports.
- Serialization from live Hotline incident tables.
- Coalesced local outbox.
- Append-only delivery history.
- Relay submission and retry behavior.

Relay owns:

- Transport, routing, delivery, handler invocation, and message metadata.
- No Hotline incident semantics.

Utility/Vena owns:

- Inbound persistence.
- Idempotent upsert by stable source incident identity.
- UI, mapping, mission planning, and utility-side workflow.
- Rejection or quarantine of unsupported payloads.

## Message Family

Use a Hotline-owned message family:

```text
hotline.incident.*
```

V1 starts with:

```text
hotline.incident.upserted
```

Later lifecycle messages may be added if consumers need them:

```text
hotline.incident.resolved
hotline.incident.discarded
```

Until then, lifecycle changes can be represented by `hotline.incident.upserted` with the current Hotline incident status.

## Identity And Idempotency

Utility/Vena must use a stable incident identity to update the same record:

```text
source_hub_id + source_system + source_incident_id
```

Relay/message idempotency must be separate from incident identity:

```text
source_hub_id + source_system + source_incident_id + revision
```

Preferred long-term shape:

```json
{
  "incident_key": "13:hotline:606",
  "revision": 7,
  "message_idempotency_key": "13:hotline:606:rev:7"
}
```

If a dedicated revision does not exist in the first implementation, use exported `updated_at` as a temporary revision source:

```text
13:hotline:606:updated:2026-07-06T10:15:00Z
```

## Payload Contract V1

Payload must be a toned-down current-state snapshot built from live Hotline records, not SITREP JSON.

Minimum envelope:

```json
{
  "schema_version": 1,
  "message_type": "hotline.incident.upserted",
  "incident_key": "13:hotline:606",
  "revision": 7,
  "message_idempotency_key": "13:hotline:606:rev:7",
  "source": {
    "hub_id": "13",
    "system": "hotline",
    "incident_id": "606",
    "incident_ref": "000606",
    "hotline_url": "https://hotline.pbb.ph"
  },
  "incident": {
    "status": "Active",
    "created_at": "2026-07-06T10:00:00+08:00",
    "updated_at": "2026-07-06T10:15:00+08:00",
    "reported_at": "2026-07-06T10:00:00+08:00",
    "answered_at": null,
    "call_started_at": null,
    "call_ended_at": null,
    "location": {
      "label": "Main Road, Barangay Apas",
      "lat": 10.12345,
      "lng": 123.12345
    },
    "type": {
      "id": 12,
      "name": "Flooding",
      "category_id": 4,
      "category_name": "Disaster & Weather"
    },
    "details": {},
    "resources": [],
    "team_assignments": [],
    "media_refs": []
  },
  "raw": {
    "retention_hint": "short_debug",
    "incident": {}
  }
}
```

Payload should include these areas where available:

- source node identity
- source incident id and display reference
- status
- location label and coordinates
- incident type and incident category
- important timestamps
- toned-down type details
- requested/default resource context
- team assignments and assignment status timestamps
- media references or local URL paths where already available
- short-retention raw/debug data

Do not include secrets, internal storage paths, private filesystem paths, or user session data.

## Outbox And Delivery Model

Do not upsert sent delivery history.

Use two layers.

### Coalesced Outbox

One pending row per incident:

```text
incident_relay_outbox
- incident_id unique
- desired_message_type
- pending_since
- last_changed_at
- attempts
- status: pending / processing / failed
```

Rapid operator edits update the same pending outbox row.

### Append-Only Delivery History

One row per exported message:

```text
incident_relay_deliveries
- incident_id
- revision
- idempotency_key unique
- message_type
- payload_hash
- payload_summary
- relay_message_id
- status
- attempted_at
- sent_at
- failed_at
- last_error
```

The worker reads the latest incident state, assigns the next revision/idempotency key, appends a delivery row, submits to Relay, then updates delivery status.

## Trigger Rules

Hotline incident reports are built progressively during live calls.

Candidate triggers:

- incident created when a call is answered
- location updated
- incident type/details updated
- team assignment changes
- media finalized or made available
- incident status changes

Every trigger should only mark/update the outbox row. It should not directly send a Relay message.

The worker should send after a short debounce/coalescing interval so active edits do not flood Relay.

Suggested first interval: 5 to 15 seconds.

## Relay Submission

Use canonical Relay `targets[]` shape.

Suggested default target system:

```text
utility.vena
```

Example:

```json
{
  "source_system": "hotline.incident",
  "targets": [
    {
      "id": "11",
      "systems": ["utility.vena"]
    }
  ],
  "message_type": "hotline.incident.upserted",
  "payload": {}
}
```

Target hub ids should come from the same trusted hub/uplink source used by other Relay integrations.

## Settings

Add settings separate from SITREP and Support Request:

```text
incident_relay_enabled
incident_relay_source_system = hotline.incident
incident_relay_target_systems = utility.vena
incident_relay_debounce_seconds = 10
```

Settings should be visible in the existing admin settings surface if this becomes runtime-configurable.

## Implementation Slices

### Slice 1: Contract And Serializer

- Add this contract to developer docs.
- Create incident export serializer from live Hotline incident models/tables.
- Include location, type/category, details, resources, assignments, timestamps, and media refs.
- Add tests for sparse incident, detailed incident, assigned teams, and media refs.
- Add a local command to serialize one incident without sending.

### Slice 2: Manual Relay Export

- Add Relay submission service for `hotline.incident.upserted`.
- Add manual command to export one incident to Relay.
- Add focused tests for Relay envelope, target systems, idempotency key, and payload hash.
- Coordinate with Utility/Vena using sample payloads.

### Slice 3: Outbox And Worker

- Add `incident_relay_outbox`.
- Add `incident_relay_deliveries`.
- Add worker command to process pending outbox rows.
- Add retry/failure handling.
- Ensure sent delivery rows are append-only.
- Ensure pending outbox rows coalesce rapid updates.

### Slice 4: Runtime Hooks

- Mark outbox pending on meaningful incident changes.
- Hook team assignment changes.
- Hook media finalize/availability.
- Hook lifecycle status changes.
- Avoid sending on every keystroke or non-material save.

### Slice 5: Admin Visibility

- Add admin view or log summary for incident relay deliveries.
- Show pending/failed counts.
- Allow safe retry for failed rows.
- Do not expose raw secrets or filesystem paths.

## Validation Checklist

- Sparse empty incident exports successfully.
- Later update for the same incident keeps the same `incident_key`.
- Later update gets a new message idempotency key/revision.
- Rapid repeated edits coalesce into one pending outbox row.
- Sent delivery history is append-only.
- Utility/Vena can upsert by stable incident identity.
- Missing location is accepted but explicit.
- Missing incident type is accepted for early call-created incidents.
- Team assignment timestamps are preserved.
- Media refs do not expose internal storage paths.
- Relay failures are visible and retryable.
- SITREP relay and Support Request relay tests remain unaffected.

## Open Questions

- Should Hotline add a dedicated `incident_export_revision` column, or derive revision from delivery sequence first?
- Should resolved/discarded get dedicated message types in V1 or remain status updates under `hotline.incident.upserted`?
- What exact target system name should Utility/Vena publish: `utility.vena`, `utility.dispatch`, or another value?
- What media URL contract should Utility consume first: `media_refs` only, local URL paths, or both?
