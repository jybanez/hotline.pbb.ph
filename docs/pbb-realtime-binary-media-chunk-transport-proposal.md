# PBB Realtime Binary Media Chunk Transport Proposal

## Context

Hotline Beta currently publishes operator-side media chunks through Realtime using the existing `media.chunk.publish` websocket request. The current payload carries media metadata plus `chunk_data` as a base64 string. Realtime accepts the request, queues the chunk, forwards it to the configured downstream ingest endpoint, and reports the downstream result back to the sender through `media.chunk.forwarded` or `media.chunk.failed`.

This path is now functionally aligned with the caller/operator offline-first queue design, but the base64 encoding layer adds avoidable cost:

- Browser `Blob -> base64` conversion reads and encodes each full chunk before it can be stored or published.
- Base64 expands chunk data by roughly 33 percent before JSON and websocket framing.
- Realtime and Hotline ingest both process larger JSON payloads than the raw media bytes.
- Hotline bootstrap and compatibility paths may decode base64 back into `Blob` or bytes, creating more memory churn.

Hotline Beta wants to preserve the current Realtime ownership boundary while adding a binary-capable transport mode before deciding whether media ingest should move to a Hotline-hosted Node.js service.

## Goals

- Add binary media chunk support to Realtime without breaking the existing base64 `media.chunk.publish` contract.
- Keep Realtime generic and shared-service friendly; do not bake Hotline-specific endpoint/header semantics into the browser-facing protocol.
- Preserve current sender semantics: websocket ACK means Realtime accepted/queued the chunk, and later `media.chunk.forwarded` / `media.chunk.failed` reports downstream forwarding outcome.
- Let Hotline store chunks as `Blob` in IndexedDB and publish the original binary bytes without base64 conversion.
- Keep Hotline media creation and finalize flows unchanged.

## Non-Goals

- Realtime does not need to store complete media files or assemble recordings.
- Realtime does not need to fan out raw media chunks to room subscribers.
- Realtime does not need to become Hotline-specific.
- This proposal does not require replacing the current base64 transport immediately.

## Proposed Contract

Add an additive binary mode for media chunk publishing.

### Prepare Request

Browser sends a JSON request over the existing Realtime websocket:

```json
{
  "type": "media.chunk.prepare",
  "room": "media-ingest-room",
  "payload": {
    "transfer_id": "uuid-or-request-id",
    "incident_id": 131,
    "call_session_id": 139,
    "media_id": 304,
    "segment_key": "caller-audio-...",
    "chunk_index": 12,
    "total_bytes": 42137,
    "mime_type": "audio/webm;codecs=opus",
    "extension": "weba",
    "track_kind": "audio",
    "media_type": "audio_peer",
    "peer_user_id": 3,
    "peer_role": "caller"
  }
}
```

Realtime validates the sender, room join, project media-ingest availability, payload shape, and policy exactly like current `media.chunk.publish`.

If accepted, Realtime replies with ACK:

```json
{
  "phase": "ack",
  "type": "media.chunk.prepare",
  "payload": {
    "transfer_id": "uuid-or-request-id",
    "accepted": true,
    "delivery": "awaiting_binary"
  }
}
```

### Binary Frame

After prepare ACK, browser sends a binary websocket message containing:

- a small fixed header or envelope prefix that identifies `transfer_id`, followed by raw chunk bytes; or
- a Realtime-supported binary frame format if one already exists or is preferred.

The exact framing is for Realtime to decide, but the frame must correlate unambiguously to the prior prepare request. Realtime should reject orphan binary frames, duplicate transfer ids, expired prepares, wrong byte length, and binary payloads exceeding configured limits.

### Queue And Forward

After receiving the binary frame, Realtime queues the raw bytes with the metadata from the prepare request. Forwarding remains asynchronous inside the running `realtime:serve` loop, matching the current DB-backed media chunk dispatcher model.

Downstream forwarding should remain adapter/config driven per project scope. For Hotline Beta, the first concrete downstream target can remain the current internal Hotline media-chunk ingest seam, updated to accept binary or multipart input.

### Outcome Events

Realtime continues to emit the existing outcome events:

- `media.chunk.forwarded`
- `media.chunk.failed`

Payload should include at minimum:

```json
{
  "transfer_id": "uuid-or-request-id",
  "media_id": 304,
  "segment_key": "caller-audio-...",
  "chunk_index": 12,
  "call_session_id": 139
}
```

This lets Hotline Consumer keep the same forwarded/failed retry logic.

## Hotline-Side Migration Shape

Hotline can introduce transport selection behind the existing Consumer transport interface:

- `RealtimeBase64ChunkTransport`: current `media.chunk.publish` path.
- `RealtimeBinaryChunkTransport`: new prepare + binary frame path.
- Future `HotlineNodeChunkTransport`: direct Hotline-hosted Node.js path if needed.

Producer should eventually persist raw `Blob` chunks in IndexedDB instead of only base64 `chunk_data`. Consumer can then choose the transport-specific serialization at publish time.

During transition, Hotline can store `Blob` plus metadata and keep base64 conversion only inside the explicit `realtime-base64` transport option.

## Failure Handling

Recommended failure behavior:

- Prepare rejected: treat as publish failure; Hotline retries chunk according to current Consumer retry policy.
- Prepare ACK timeout: treat as publish failure.
- Binary frame send failure or socket close before binary receipt: Realtime should expire the prepare; Hotline retries.
- Binary byte length mismatch: Realtime rejects/fails the transfer and emits/logs a failure.
- Downstream ingest failure: Realtime emits `media.chunk.failed`; Hotline retries up to its configured limit.
- Duplicate `transfer_id`: Realtime should be idempotent where possible, otherwise reject deterministically.

## Open Questions For Realtime

- Preferred binary frame format: fixed prefix, JSON header + separator, or another Realtime-native envelope?
- Should the prepare ACK mean `awaiting_binary`, while final queue acceptance is only visible through a second ACK/event after binary receipt?
- Where should raw binary chunks be held before dispatcher forwarding: DB blob column, filesystem temp storage, or another queue-backed storage?
- What size limit should apply per binary frame for V1?
- Should binary support be exposed as a project-scope media-ingest setting so clients can discover whether to use binary or base64?

## Recommended First Slice

1. Keep existing base64 `media.chunk.publish` unchanged.
2. Add Realtime SDK capability to send a prepared binary media chunk.
3. Add Realtime server support for prepare + correlated binary frame.
4. Queue the chunk into the existing media dispatcher path.
5. Keep `media.chunk.forwarded` / `media.chunk.failed` unchanged from Hotline’s point of view.
6. Add Hotline transport adapter `RealtimeBinaryChunkTransport` only after Realtime has the prepare/binary contract ready.
