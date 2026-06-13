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
use Pbb\Hotline\Media\MediaRef;
use Pbb\Hotline\Media\MediaRefLocalUrl;
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

## App-Local Media URLs

Upstream apps should expose their own local media routes to the browser and keep source Hotline authentication on the backend. The SDK provides `MediaRefLocalUrl` to derive stable local paths and cache keys from SITREP media refs:

```php
use Pbb\Hotline\Media\MediaRefLocalUrl;

$localUrl = new MediaRefLocalUrl();

$path = $localUrl->path($ref);
$cacheKey = $localUrl->cacheKey($ref);
```

The route shape is:

```text
/media/{source_hub_id}/{incident_id}/incident_media/{media_type}/{media_id}
/media/{source_hub_id}/{incident_id}/message_attachment/{message_id}/{attachment_id}
```

Examples:

```text
/media/13/593/incident_media/citizen_video/501
/media/13/605/message_attachment/701/601
```

The consuming app backend should check its local cache first. On a cache miss, it can use the ref represented by the route to request the source Hotline manifest/download, cache the bytes, and stream the result to the browser.

For normal backend route implementation, prefer the higher-level `MediaRef` wrapper so cache ownership stays inside the SDK. The app supplies only the selected ref, cache storage location, and source auth/base URL config:

```php
use Pbb\Hotline\Media\MediaRef;

$media = new MediaRef($ref, __DIR__.'/cache/hotline-media', [
    'relay_token' => $settings->relayToken,
]);

$media->serve();
```

If the app needs to inspect the result instead of streaming immediately:

```php
$result = $media->resolve();
```

`resolve()` checks the SDK cache first. It contacts the source Hotline manifest/download endpoints only when the selected media is not already cached.

When only `relay_token` is provided, the SDK requires the local Relay to be reachable at `https://relay.pbb.ph`. It reads the local hub id from `https://relay.pbb.ph/hub.json`, resolves the relationship through `POST https://relay.pbb.ph/api/v1/relationships/resolve`, then uses the returned source domain and link token for the source Hotline media request. If `relay.pbb.ph` is not reachable in the local network, the SDK fails loudly because the machine is not acting as a valid PBB hub.

## Demo

The package includes a source-only CLI demo under `packages/pbb-hotline-media-sdk/demo`.

Dry-run mode parses a SITREP payload, extracts media refs, resolves source Hotline hubs, and prints the manifest calls that would be made:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe packages\pbb-hotline-media-sdk\demo\resolve.php --dry-run
```

Live mode requires a media access token configured on the source Hotline hub:

```powershell
$env:HOTLINE_MEDIA_ACCESS_TOKEN="paste-token-here"
C:\wamp64\bin\php\php8.2.29\php.exe packages\pbb-hotline-media-sdk\demo\resolve.php --sitrep=Z:\tmp\sitreps\new\consolidated.sitrep.json
```

See `packages/pbb-hotline-media-sdk/demo/README.md` for the full command options.

## Cache Ownership

The SDK supports cache hit and cache miss flows through `MediaCacheInterface`. Caller apps own local user authorization, cache paths, retention, purge policy, and UI presentation.

Relay transports SITREP and support messages. Relay does not transport Hotline media files. CORS may help browser display after an app has authorized a user locally, but it is not the authorization model.
