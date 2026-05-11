# PBB Realtime Product Backend Query Proposal

Date: 2026-05-11

Status: Draft for Realtime review.

## Summary

Add a narrow Realtime capability that lets a connected browser client ask its product backend for an authoritative, lightweight state refresh without leaving the existing websocket path.

Realtime should not own product data or query product databases. Realtime should validate and forward explicitly allowed request events to the product backend. The product backend should authorize the request, resolve the latest state, and publish a server-originated response back into the requested Realtime room through the existing backend event ingress.

The immediate Hotline use case is post-call incident reconciliation on the citizen surface. Instead of making repeated `/api/citizen/home` HTTP requests after call end, the citizen client can send a small realtime query. Hotline responds with only the current incident state needed by the citizen UI.

## Goals

- Keep thin-network citizen clients lightweight after call end.
- Avoid repeated full `/api/citizen/home` polling.
- Use the already-open Realtime websocket when it is healthy.
- Keep product backends as the source of truth for business authorization and state.
- Make the request/response envelope versioned and extensible enough for future richer payloads.
- Allow each product to opt in only specific request types, routes, payload limits, and response rooms.

## Non-Goals

- Realtime does not query Hotline database tables.
- Realtime does not understand Hotline incident status semantics.
- Realtime does not forward arbitrary browser events to product backends.
- Realtime does not replace the existing backend event publish ingress.
- This proposal does not remove HTTP APIs used for initial page load or authenticated write actions.

## Proposed Flow

1. Browser joins an authorized Realtime room, such as `presence.global.hotline`.
2. Browser publishes an app event that is configured as product-backend-forwardable.
3. Realtime validates token, capability, joined room, configured event type, payload size, and rate limits.
4. Realtime POSTs a normalized request envelope to the product backend.
5. Product backend authenticates the request using a backend-only shared secret.
6. Product backend authorizes the product user/context and resolves current state.
7. Product backend publishes a response event back to Realtime through `POST /api/v1/events/publish`.
8. Realtime fans out the response to the room.
9. Browser applies the response if the `request_id`, `query`, `context`, and `schema_version` are recognized.

## Realtime Request Event

Use the existing app-event lane from the browser:

```json
{
  "event_type": "product.query.request",
  "data": {
    "schema_version": 1,
    "request_id": "qry_01HX...",
    "query": "hotline.incident.snapshot",
    "context": {
      "incident_id": 204
    },
    "projection": {
      "preset": "status",
      "fields": ["incident.id", "incident.status", "incident.updated_at"]
    },
    "client_state": {
      "last_seen_version": "2026-05-11T07:40:48Z",
      "reason": "post-call-reconcile"
    }
  },
  "correlation_id": "qry_01HX..."
}
```

`event_type` can be generic (`product.query.request`) or product-scoped (`hotline.query.request`). The generic form is preferred if Realtime owns the forwarding contract and product routing is configured by project code plus query allowlist.

## Realtime Forwarded Backend Envelope

Realtime forwards a normalized HTTP request to the configured product backend endpoint.

Suggested endpoint shape:

```http
POST /api/internal/realtime/product-query
X-Realtime-Backend-Secret: <secret>
Content-Type: application/json
```

Suggested body:

```json
{
  "type": "product.query.request",
  "schema_version": 1,
  "client_code": "clt_...",
  "project_code": "prj_...",
  "room": "presence.global.hotline",
  "request": {
    "request_id": "qry_01HX...",
    "query": "hotline.incident.snapshot",
    "context": {
      "incident_id": 204
    },
    "projection": {
      "preset": "status",
      "fields": ["incident.id", "incident.status", "incident.updated_at"]
    },
    "client_state": {
      "last_seen_version": "2026-05-11T07:40:48Z",
      "reason": "post-call-reconcile"
    }
  },
  "meta": {
    "service": "PBB Realtime",
    "source": "client",
    "received_at": "2026-05-11T07:40:53Z",
    "correlation_id": "qry_01HX...",
    "sender": {
      "user_id": "3",
      "display_name": "Citizen Name",
      "project_code": "prj_...",
      "app_code": "clt_..."
    }
  }
}
```

## Product Backend Response Event

The product backend responds by publishing a server-originated event to Realtime. This keeps Realtime generic and reuses the existing backend ingress.

```json
{
  "event_type": "product.query.response",
  "payload": {
    "schema_version": 1,
    "request_id": "qry_01HX...",
    "query": "hotline.incident.snapshot",
    "context": {
      "incident_id": 204
    },
    "status": "ok",
    "data": {
      "incident": {
        "id": 204,
        "status": "Resolved",
        "updated_at": "2026-05-11T07:40:48Z"
      }
    }
  },
  "meta": {
    "source": "backend",
    "source_module": "hotline-beta"
  }
}
```

The response event may be product-scoped if needed, for example `hotline.query.response`. A generic event name is preferable only if the payload includes `query` and `schema_version` and clients ignore unknown queries.

## Payload Evolution

The request must assume future queries may need more than status.

Use these extension points:

- `schema_version`: integer version for the query envelope.
- `query`: stable query identifier, such as `hotline.incident.snapshot`.
- `projection.preset`: product-owned shorthand, such as `status`, `summary`, `call_state`, `media_state`, or `full_current_incident`.
- `projection.fields`: optional field list for narrow responses.
- `context`: product-owned identity keys, such as `incident_id`, `call_session_id`, or `thread_id`.
- `client_state`: optional hints for differential responses, such as `last_seen_version`, `last_seen_status`, or `reason`.
- `data`: response payload object whose shape is owned by the product and query.

Clients must treat unknown fields as additive. Product backends should avoid breaking old clients by adding new fields under `data` or introducing a new `schema_version`.

## Hotline V1 Query

Query:

`hotline.incident.snapshot`

V1 presets:

- `status`: current incident id, status, updated timestamp, terminal timestamp fields if present.
- `call_state`: status preset plus latest call session id/status/outcome/answered/ended timestamps.
- `summary`: call_state plus current operator id/name and minimal incident label fields.

Initial citizen post-call reconcile should request only:

```json
{
  "query": "hotline.incident.snapshot",
  "projection": {
    "preset": "status"
  }
}
```

If the incident is terminal, the citizen surface can clear the active incident without fetching `/api/citizen/home`. If the incident is still active and unchanged, the citizen surface does nothing.

## Realtime Configuration

Realtime should require explicit product/project configuration for forwarded query events.

Suggested per-project settings:

```json
{
  "product_query_forwarding": {
    "enabled": true,
    "base_url": "https://hotline.pbb.ph",
    "path": "/api/internal/realtime/product-query",
    "auth_header": "X-Realtime-Backend-Secret",
    "auth_token": "<stored-secret-reference>",
    "allowed_event_types": ["product.query.request"],
    "allowed_queries": ["hotline.incident.snapshot"],
    "max_payload_bytes": 4096,
    "rate_limit_per_minute": 12,
    "connect_timeout_seconds": 3,
    "timeout_seconds": 8,
    "verify_tls": true
  }
}
```

This mirrors the existing media-ingest forwarding pattern, but for small JSON query requests.

## Security

- Only forward from authenticated Realtime sessions.
- Require `event.publish`.
- Require the client to have joined the request room.
- Require explicit event type and query allowlists.
- Enforce small payload limits.
- Enforce per-session and per-project rate limits.
- Use backend-only shared secret for the product callback.
- Include sender user id and project/app code in the forwarded envelope.
- Product backend must re-authorize the sender against the requested context.
- Product backend should publish responses only to rooms the requester is expected to be listening to.

## Failure Behavior

Realtime should ack the browser publish once the request is accepted for forwarding, not once product state is resolved.

If forwarding fails, Realtime can emit an optional response event:

```json
{
  "schema_version": 1,
  "request_id": "qry_01HX...",
  "query": "hotline.incident.snapshot",
  "status": "error",
  "error": {
    "code": "product-query.forward-failed",
    "message": "Product backend did not accept the query."
  }
}
```

The citizen surface should not retry aggressively on failure. For Hotline post-call reconcile, one request is enough.

## Open Questions For Realtime

- Should this be a generic `product.query.request` forwarding lane, or should products configure their own event names?
- Should Realtime emit a standardized failure response on backend callback failure?
- Should forwarded query settings live on `RealtimeProject`, similar to media ingest settings, or on a new per-project integration table?
- Should responses always use the existing backend publish ingress, or should Realtime support synchronous callback response fanout later?

## Acceptance Criteria

- Realtime forwards an allowlisted small query event from browser websocket to a configured product backend endpoint.
- Hotline can respond with a backend-originated `product.query.response` / `hotline.query.response` event.
- Citizen post-call reconcile no longer requires repeated `/api/citizen/home` calls.
- A failed query does not leave the citizen UI stuck and does not retry in a tight loop.
- Payloads are versioned and can expand from status-only to richer incident snapshots without changing the transport mechanism.
