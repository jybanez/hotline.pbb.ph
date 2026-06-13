# Hotline Media SDK Demo

This demo shows how an upstream app reads SITREP `media_refs`, resolves the source Hotline hub, asks for a media manifest, and optionally downloads media into a caller-owned cache.

Dry-run output also shows the app-local media URL and cache key that an upstream backend can expose to its own frontend:

```text
/media/{source_hub_id}/{incident_id}/incident_media/{media_type}/{media_id}
/media/{source_hub_id}/{incident_id}/message_attachment/{message_id}/{attachment_id}
```

For a backend route, the intended SDK usage is:

```php
$media = new \Pbb\Hotline\Media\MediaRef($ref, __DIR__.'/cache/hotline-media', [
    'relay_token' => $settings->relayToken,
]);

$media->serve();
```

The SDK checks cache first, resolves the source relationship through local Relay at `https://relay.pbb.ph` on cache miss, fetches from source Hotline, stores the media, then streams it.

The demo is source-only. It is not part of installed Hotline runtime bundles.

## Dry Run

Dry run parses the sample SITREP and prints the planned source hub calls without requiring a token or making HTTP requests.

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe packages\pbb-hotline-media-sdk\demo\resolve.php --dry-run
```

Use a real SITREP payload:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe packages\pbb-hotline-media-sdk\demo\resolve.php --dry-run --sitrep=Z:\tmp\sitreps\new\consolidated.sitrep.json
```

## Live Manifest And Cache

Live mode requires a token configured on the source Hotline hub as `sitrep_media_access_token`.

```powershell
$env:HOTLINE_MEDIA_ACCESS_TOKEN="paste-token-here"
C:\wamp64\bin\php\php8.2.29\php.exe packages\pbb-hotline-media-sdk\demo\resolve.php --sitrep=Z:\tmp\sitreps\new\consolidated.sitrep.json
```

Override the source base URL when the SITREP does not contain a usable source hub URL:

```powershell
C:\wamp64\bin\php\php8.2.29\php.exe packages\pbb-hotline-media-sdk\demo\resolve.php --base-url=https://hotline.pbb.ph
```

Downloaded files are stored under `packages/pbb-hotline-media-sdk/demo/cache` by default. Caller apps own cache retention, purge policy, and local user authorization.
