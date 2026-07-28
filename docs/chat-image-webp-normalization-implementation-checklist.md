# Chat Image WebP Normalization Implementation Checklist

This checklist tracks the Hotline-side implementation for normalizing chat photos to WebP, with a separate lightweight note for avatars.

Related proposal:

- `docs/chat-image-webp-normalization-proposal.md`

## Product Boundary

- Hotline stores operational chat images, not forensic originals.
- Chat photos can be normalized to WebP and treated as authoritative stored evidence records.
- GPS/location, timestamps, sender identity, incident linkage, and message context remain database-owned.
- Device metadata and original upload bytes are intentionally not retained for this scope.
- Screenshots/document images are not a Hotline operational image category.
- Avatar images are UI assets and should not use chat-photo evidence metadata.

## 1. Database

- Add an additive migration for `message_attachments`.
- Add nullable columns:
  - `original_mime_type`
  - `stored_mime_type`
  - `stored_filename`
  - `stored_size_bytes`
  - `image_width`
  - `image_height`
  - `sha256`
  - `normalized_at`
- Add indexes only if query patterns need them.
- Keep existing columns for backward compatibility:
  - `type`
  - `mime_type`
  - `original_filename`
  - `stored_path`
  - `file_size`
  - `thumbnail_path`
  - `uploaded_by`
  - `created_at`
- Update baseline schema and migration ledger rows.
- Update `release.json` database metadata if the migration is included in a release.

## 2. Image Normalization Service

- Add a small app-owned service for image normalization.
- Accept uploaded image file and target options.
- Decode using the available PHP image stack.
- Normalize orientation when supported.
- Strip metadata.
- Downscale to configured max long edge.
- Encode to WebP.
- Return:
  - bytes or temp path;
  - width;
  - height;
  - stored filename;
  - stored mime type;
  - stored size;
  - SHA-256.
- Fail clearly when the image cannot be decoded or encoded.

## 3. Chat Attachment Ingest

- Update `IncidentMessageController::storeAttachment`.
- For chat photo attachments:
  - normalize to WebP before storing;
  - store the WebP path in `stored_path`;
  - set `mime_type` to `image/webp` for new normalized records, or document if `mime_type` remains legacy;
  - fill new metadata columns.
- For non-photo attachments:
  - preserve current behavior unless explicitly changed later.
- Keep validation restrictions aligned with existing Realtime attachment policy.
- Keep `thumbnail_path` behavior compatible:
  - either generate WebP thumbnail/preview;
  - or leave nullable if the normalized WebP is sufficient for current UI.

## 4. Model And Serialization

- Update the `MessageAttachment` model fillable/casts.
- Add casts for:
  - `stored_size_bytes`
  - `image_width`
  - `image_height`
  - `normalized_at`
- Update API responses to include normalized metadata.
- Preserve old response fields so current Command/Operator surfaces do not break.
- Ensure frontend URL normalization still uses `stored_path` and `thumbnail_path` correctly.

## 5. UI And Frontend

- Confirm chat image previews render WebP correctly in:
  - Operator surface;
  - Citizen surface;
  - Command incident detail views if applicable.
- Use `image_width` and `image_height` for layout if available.
- Avoid adding UI copy about conversion unless an operator-facing status/debug panel needs it.

## 6. Avatar Handling

- If Hotline stores local avatars, normalize them as lightweight WebP UI assets.
- Generate only the variants the UI needs, such as:
  - `64`;
  - `128`;
  - `256`.
- Track only simple avatar asset data:
  - stored path or URL;
  - MIME type;
  - variant size;
  - updated timestamp or version token for cache busting.
- Do not add avatar rows to `message_attachments`.
- Do not require avatar metadata fields such as:
  - `original_mime_type`;
  - `original_filename`;
  - `sha256`;
  - `normalized_at`.
- If Account SSO becomes the avatar source of truth, defer to the Account avatar contract instead of inventing a Hotline-specific one.

## 7. SITREP And Incident Relay Metadata

- Update SITREP media ref generation for message attachments to include normalized metadata when available:
  - stored mime type;
  - dimensions;
  - size;
  - SHA-256.
- Update Incident Relay serializer similarly.
- Do not expose `stored_path` or filesystem paths in Relay/SITREP refs.
- Keep media SDK contracts backward-compatible.

## 8. Optional Backfill

- Decide whether existing image attachments should be backfilled.
- If yes, add an Artisan command:
  - dry-run by default;
  - batch size option;
  - skip already normalized rows;
  - record failures without stopping the full run;
  - avoid deleting old files until explicitly requested.
- If no, document that normalization applies only to new chat photo uploads.

## 9. Tests

- Feature test: photo upload stores WebP.
- Feature test: metadata columns are populated.
- Feature test: original filename is preserved as label but original bytes are not retained.
- Feature test: non-photo attachment behavior remains unchanged.
- Unit test: normalization service downscales oversized image.
- Unit test: SHA-256 matches stored WebP bytes.
- Serialization test: API returns normalized fields and old fields.
- SITREP/Incident Relay test: refs include normalized metadata and exclude storage paths.
- Regression test: existing legacy rows without new metadata still render.
- Avatar test, only if Hotline owns avatar storage: avatar WebP variants render and cache-busting value changes on update.

## 10. Verification

- Upload a JPEG chat photo.
- Confirm stored file extension and MIME are WebP.
- Confirm image opens in browser.
- Confirm chat preview renders in Operator/Citizen surfaces.
- Confirm `message_attachments` row has:
  - `original_mime_type`;
  - `stored_mime_type=image/webp`;
  - dimensions;
  - stored size;
  - SHA-256;
  - `normalized_at`.
- Compare file size before and after normalization using a representative phone photo.
- Run relevant PHP feature tests.
- Run JS surface tests if response shape changes affect frontend code.
- Run `npm run build`.

## 11. Release And Installer

- Mark `release.json.update.requires_database_migration=true`.
- Update fresh baseline schema.
- Confirm installable bundle includes no temporary conversion files.
- Confirm no source upload originals are packaged or left in temp paths.
- Document whether existing installed nodes need a backfill command.

## Current Recommendation

- Normalize new chat photo uploads only for the first implementation.
- Do not backfill old attachments unless storage pressure requires it.
- Use WebP quality `82-85`.
- Use max long edge `1600` unless field testing shows response teams need higher detail.
