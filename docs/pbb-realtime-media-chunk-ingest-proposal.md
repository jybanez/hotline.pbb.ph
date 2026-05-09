# PBB Realtime Media Chunk Ingest Proposal

## Context

Hotline Beta currently records:
- operator local audio
- remote caller audio
- segmented remote caller video

Chunks are persisted immediately to Hotline-owned temporary storage through recurring HTTP POST requests, then merged/finalized after call end.

This works, but it leaves browser-side chunk delivery on HTTP while the rest of the live call already uses Realtime websocket transport.

## Goal

Move live call-media chunk delivery from browser-to-Hotline HTTP POST into websocket transport, without moving media ownership into Realtime.

## Boundary

Realtime remains transport-only.

Realtime should not own:
- temporary media storage
- chunk persistence tables
- segment assembly
- media rows
- merge/finalize lifecycle

Hotline remains responsible for:
- temporary chunk storage
- merge/finalize
- media rows
- follow-up business events such as `media.processing` and `media.available`

## Proposed V1 Shape

### 1. Browser -> Realtime websocket request

Request type:
- `media.chunk.publish`

Envelope:
- normal Realtime websocket request
- normal `room` field still used

Payload:
- `incident_id`
- `call_session_id`
- `media_id`
- `type`
  - `audio_peer`
  - `caller_video`
- `peer_user_id`
- `peer_role`
- `track_kind`
  - `audio`
  - `video`
- `mime_type`
- `extension`
- `segment_key`
- `chunk_index`
- optional `chunk_total`
- optional `total_bytes`
- `chunk_data`
- optional `correlation_id`

Notes:
- `chunk_data` can stay base64 in V1 if that keeps Realtime implementation simple
- no room fanout of raw chunks

### 2. Realtime -> Browser ack/error

Ack should be sender-only.

Suggested ack payload:
- `accepted: true`
- `media_id`
- `segment_key`
- `chunk_index`
- optional `correlation_id`

Error should also be sender-only and use normal Realtime validation/auth/rate-limit patterns.

### 3. Realtime -> Hotline forward seam

After Realtime validates and accepts a chunk, it should immediately forward it to a Hotline-owned ingest endpoint.

Suggested Hotline endpoint:
- `POST /api/internal/media/chunks`

Suggested auth:
- `X-Hotline-Media-Ingest-Secret`

Forwarded body should include:
- original chunk payload
- Realtime sender metadata:
  - `sender_user_id`
  - `sender_display_name`
  - `project_code`
  - `app_code`
  - `room`
- server/request ids as useful for tracing

## Hotline Responsibilities After Forward

Hotline should:
- validate sender/room/context consistency
- store the chunk in Hotline-owned temp storage
- keep the current media merge/finalize pipeline
- keep server-originated `media.processing` and `media.available` broadcasts

## Why This V1 Is Enough

- removes recurring browser-side HTTP POSTs for live media chunks
- keeps Realtime transport-only
- keeps Hotline as sole owner of media lifecycle
- avoids overloading `sandbox.attachment.chunk.publish`
- limits integration scope to one clear forward seam

## Recommended Next Step

If Realtime accepts this direction:
1. Realtime defines `media.chunk.publish` request and sender-only ack contract
2. Hotline adds `/api/internal/media/chunks`
3. Browser/runtime switches from direct chunk POSTs to websocket `media.chunk.publish`
