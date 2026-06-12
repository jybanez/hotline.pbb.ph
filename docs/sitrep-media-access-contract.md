# SITREP Media Access Contract

Hotline SITREP media references are identifiers, not file URLs. `incident_media` and `message_attachment` refs may appear in SITREP payloads so upstream PBB apps can discover relevant media, but the raw storage path remains Hotline-owned and must not be treated as the security model.

## Internal API

Hotline exposes two authenticated internal endpoints:

- `POST /api/internal/sitrep/media/manifest`
- `GET /api/internal/sitrep/media/{kind}/{id}`

Supported `kind` values:

- `incident_media`
- `message_attachment`

The manifest endpoint accepts `media_refs` or `refs` arrays from a SITREP payload. It returns `items[]` for available media and `unavailable[]` for rejected, missing, context-mismatched, or unavailable refs. Available items include metadata and a Hotline download URL, but never include raw storage paths or public storage URLs.

The download endpoint validates the same internal token, resolves the requested item by kind and id, checks optional context query parameters such as `incident_id` and `message_id`, logs the access attempt, and streams the file through Laravel storage response APIs.

## Authentication

The first-pass contract uses the `sitrep_media_access_token` runtime setting. Callers can provide it through:

- `X-Hotline-Media-Key`
- `X-Hotline-Media-Token`
- `Authorization: Bearer <token>`

Callers should also send `X-PBB-Source-System` and `X-PBB-Source-Hub-Id` for audit logging.

## SDK

The PHP SDK lives in `packages/pbb-hotline-media-sdk` and is framework-light. It provides:

- `HotlineMediaClient`
- `SitrepMediaRefResolver`
- `MediaCacheInterface`
- `FilesystemMediaCache`

The resolver extracts media refs from direct, consolidated, and multi-hop SITREP payloads and maps source hub ids to Hotline base URLs where hub metadata is present. The client requests a manifest, downloads available items, and reports cache hit, download, and failure states with structured metadata.

The SDK includes a source-only CLI demo at `packages/pbb-hotline-media-sdk/demo`. Start with dry-run mode to inspect a SITREP payload without requiring a token:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe packages\pbb-hotline-media-sdk\demo\resolve.php --dry-run
```

Upstream apps own local user authorization, cache location, retention policy, purge behavior, and UI presentation. Relay does not transport media bytes.
