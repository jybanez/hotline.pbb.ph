# Chat Image WebP Normalization Proposal

This proposal defines the Hotline direction for storing chat photos as normalized WebP application records, with a separate lightweight path for user avatars.

The central rule is:

> Hotline stores operational chat images, not forensic originals.

For Hotline operations, GPS/location, timestamps, sender identity, incident linkage, and message context are already tracked as structured application data. Device metadata, camera tags, and original upload bytes are not required for the evidence value Hotline needs.

## Problem

Chat photos can consume unnecessary storage and bandwidth when stored in their original JPEG/PNG upload shape.

The current attachment model stores uploaded attachment metadata in `message_attachments`, including `mime_type`, `original_filename`, `stored_path`, `file_size`, and optional `thumbnail_path`. This is enough to persist files, but it does not explicitly distinguish:

- what the user originally uploaded;
- what Hotline normalized and stored;
- the authoritative hash of the stored evidence image;
- the image dimensions needed by UI, media refs, and upstream consumers.

Keeping original image bytes by default would increase storage and bandwidth for little operational benefit in Hotline’s chat-photo scope.

## Goals

- Normalize chat photo uploads to WebP during ingest.
- Store the normalized WebP as the authoritative image record.
- Extend `message_attachments` instead of adding a separate chat-image metadata table.
- Strip source image metadata intentionally.
- Preserve operational context in database fields:
  - incident id through `incident_messages`;
  - message id;
  - uploader;
  - sender/message timestamp;
  - attachment type;
  - stored mime type;
  - stored dimensions;
  - stored size;
  - stored SHA-256 hash.
- Reduce storage and bandwidth for chat photo viewing, SITREP media references, and upstream media access.
- Keep avatars WebP-based where Hotline owns avatar assets, but treat them as UI assets rather than evidence records.

## Non-Goals

- Do not retain original upload bytes by default for chat photos.
- Do not treat Hotline chat photos as forensic original evidence.
- Do not support screenshot/document image preservation as a special case; those are not part of Hotline’s operational image scope.
- Do not refactor incident video/audio handling in this change.
- Do not replace the existing `media` table used for incident media.
- Do not alter SITREP or Incident Relay media contracts except to read the new normalized attachment metadata when available.
- Do not apply chat-photo evidence metadata requirements to avatars.

## Scope

In scope:

- `message_attachments` rows for chat photo uploads.
- WebP metadata and storage-path updates.
- Thumbnail/preview generation if still needed after full-size WebP normalization.
- Lightweight user avatar WebP variants where avatars are stored by Hotline.

Out of scope:

- Incident call audio/video media chunks.
- Realtime binary transport contracts.
- External Support/Utility cache ownership.
- Original-file legal chain-of-custody policy.
- Account-owned avatar source-of-truth policy.

## Proposed Data Model

Extend `message_attachments` with additive nullable columns first, then backfill and tighten behavior after rollout.

Recommended fields:

| Column | Purpose |
| --- | --- |
| `original_mime_type` | MIME type reported by the uploaded source file before normalization. |
| `stored_mime_type` | MIME type of the authoritative stored file, expected `image/webp` for normalized chat photos. |
| `stored_filename` | Filename of the normalized stored file. |
| `stored_size_bytes` | Byte size of the normalized stored file. |
| `image_width` | Width of the normalized image in pixels. |
| `image_height` | Height of the normalized image in pixels. |
| `sha256` | SHA-256 of the normalized stored bytes. |
| `normalized_at` | Timestamp when Hotline normalized the image. |

Existing fields remain useful:

- `type` continues to represent logical attachment type such as photo/video.
- `mime_type` can remain for backward compatibility, but new code should prefer `stored_mime_type` when present.
- `original_filename` remains a user-facing source label, not evidence of original byte preservation.
- `stored_path` continues to point to the authoritative stored file.
- `file_size` can remain backward-compatible, but new code should prefer `stored_size_bytes` when present.
- `thumbnail_path` remains optional.

## Ingest Behavior

For chat photos:

1. Validate the uploaded file as an allowed image.
2. Decode image server-side.
3. Normalize orientation if supported by the image library.
4. Strip metadata.
5. Downscale oversized images to an operational max edge.
6. Encode as WebP.
7. Write the WebP file to app storage.
8. Compute SHA-256 from stored WebP bytes.
9. Store attachment metadata in `message_attachments`.

Suggested defaults:

- `stored_mime_type`: `image/webp`
- max long edge: `1600` to `2048` pixels
- WebP quality: `82` to `85`

## Avatar Handling

Avatars should not use the `message_attachments` metadata contract.

Avatars are identity UI assets, not operational evidence. If Hotline stores avatar files, the useful metadata is limited to:

- stored path or URL;
- MIME type, expected `image/webp`;
- variant size, such as `64`, `128`, or `256`;
- update timestamp or version token for cache busting.

Do not add avatar-only metadata such as `original_mime_type`, `original_filename`, `sha256`, or `normalized_at` unless a future Account/avatar ownership contract requires it.

## Evidence Position

The normalized WebP is the authoritative Hotline evidence image for chat-photo operations.

Hotline should document and expose this clearly:

- The original upload is not retained by default.
- Metadata stripping is intentional.
- Evidence context comes from Hotline records, not device metadata.
- The stored WebP hash is the integrity proof for the application evidence record.

## Read And API Behavior

Attachment API responses should remain backward-compatible while adding normalized fields.

Recommended response additions:

```json
{
  "id": 123,
  "type": "photo",
  "mime_type": "image/webp",
  "original_mime_type": "image/jpeg",
  "stored_mime_type": "image/webp",
  "original_filename": "scene.jpg",
  "stored_filename": "20260728_scene.webp",
  "stored_path": "incident-messages/55/90/20260728_scene.webp",
  "file_size": 183420,
  "stored_size_bytes": 183420,
  "image_width": 1600,
  "image_height": 900,
  "sha256": "hex-encoded-sha256",
  "normalized_at": "2026-07-28T10:25:00+08:00"
}
```

Consumers should use:

- `stored_mime_type ?? mime_type` for rendering;
- `stored_size_bytes ?? file_size` for size;
- `sha256` when validating cached media;
- `image_width` and `image_height` for layout.

## Relationship To SITREP And Incident Relay Media Refs

SITREP media refs and Incident Relay media refs should continue to avoid exposing storage paths.

When they reference message attachments, they may include normalized metadata:

- attachment id;
- incident id;
- message id;
- type;
- stored mime type;
- original filename;
- dimensions;
- size;
- SHA-256.

They should not include internal filesystem paths.

## Rollout Strategy

Use a conservative additive rollout:

1. Add nullable metadata columns to `message_attachments`.
2. Update ingest for new chat photos to store normalized WebP.
3. Keep existing attachments readable.
4. Update API serialization to prefer normalized fields when present.
5. Add optional backfill command for existing image attachments if needed.
6. After confidence, update docs and media reference examples to show normalized metadata.

## Open Decisions

- Exact max long edge: `1600` or `2048`.
- Whether to backfill existing photo attachments or only normalize new uploads.
- Whether `mime_type` and `file_size` should be updated to normalized values for new records, or kept as legacy-compatible aliases of `stored_mime_type` and `stored_size_bytes`.
- Whether Hotline continues storing local avatar variants or defers avatar storage to Account SSO.
