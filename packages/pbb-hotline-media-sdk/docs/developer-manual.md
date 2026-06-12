# PBB Hotline Media SDK

SITREP `source_snapshot.rollup.media_refs[]` entries are identifiers and metadata only. They are not public file URLs and they must not expose storage paths.

Hotline remains the source-of-truth owner for incident media and message attachments. Upstream PBB apps should request a manifest from the source Hotline hub, then download individual media items through the authenticated internal media endpoint.

## Source Hotline Endpoints

- `POST /api/internal/sitrep/media/manifest`
- `GET /api/internal/sitrep/media/{kind}/{id}`

Supported `kind` values are `incident_media` and `message_attachment`.

The manifest accepts:

```json
{
  "media_refs": [
    {
      "kind": "incident_media",
      "source_hub_id": "072217029",
      "incident_id": 123,
      "media_id": 501
    }
  ]
}
```

It returns available items with an authenticated `download_url` and reports missing or rejected refs in `unavailable[]`. It does not return raw storage paths.

## Auth

Source Hotline validates requests with the `sitrep_media_access_token` setting. Callers may send it as:

- `X-Hotline-Media-Key`
- `X-Hotline-Media-Token`
- `Authorization: Bearer <token>`

The caller may include `X-PBB-Source-System` and `X-PBB-Source-Hub-Id` for audit logging.

## SDK Example

```php
use Pbb\Hotline\Media\FilesystemMediaCache;
use Pbb\Hotline\Media\HotlineMediaClient;
use Pbb\Hotline\Media\SitrepMediaRefResolver;

$resolver = new SitrepMediaRefResolver();
$refs = $resolver->extractMediaRefs($sitrepPayload);
$sourceHubs = $resolver->resolveSourceHubs($sitrepPayload);

$client = new HotlineMediaClient([
    'base_url' => $sourceHubs['072217029'] ?? 'https://hotline.pbb.ph',
    'token' => getenv('HOTLINE_MEDIA_ACCESS_TOKEN'),
    'source_system' => 'support.dispatch',
    'source_hub_id' => '072217000',
], new FilesystemMediaCache(__DIR__.'/cache/hotline-media'));

$results = $client->resolveAndCache($refs);
```

## Cache Ownership

The SDK supports cache hit and cache miss flows through `MediaCacheInterface`. Caller apps own local user authorization, cache paths, retention, purge policy, and UI presentation.

Relay transports SITREP and support messages. Relay does not transport Hotline media files. CORS may help browser display after an app has authorized a user locally, but it is not the authorization model.
