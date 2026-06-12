<?php

namespace Pbb\Hotline\Media;

final class HotlineMediaClient
{
    private readonly HttpTransportInterface $transport;

    /**
     * @param  array{base_url?:string,token?:string,source_system?:string,source_hub_id?:string}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly ?MediaCacheInterface $cache = null,
        ?HttpTransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? new NativeHttpTransport;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<string, mixed>
     */
    public function manifest(array $refs, ?string $baseUrl = null): array
    {
        $response = $this->transport->request(
            'POST',
            $this->url('/api/internal/sitrep/media/manifest', $baseUrl),
            $this->headers(['Content-Type' => 'application/json']),
            json_encode(['media_refs' => array_values($refs)], JSON_UNESCAPED_SLASHES),
        );

        $payload = json_decode($response['body'], true);

        return [
            'status' => $response['status'] >= 200 && $response['status'] < 300 ? 'ok' : 'error',
            'http_status' => $response['status'],
            'payload' => is_array($payload) ? $payload : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function fetchAndCache(array $item, ?string $baseUrl = null): array
    {
        $key = $this->cacheKey($item);
        $cached = $this->cache?->get($key);

        if ($cached !== null) {
            return [
                ...$this->resultMetadata($item),
                ...$cached,
                'status' => 'cached',
            ];
        }

        $downloadUrl = trim((string) ($item['download_url'] ?? ''));
        if ($downloadUrl === '') {
            return [
                ...$this->resultMetadata($item),
                'status' => 'failed',
                'error' => 'missing_download_url',
            ];
        }

        $response = $this->transport->request('GET', $this->absoluteUrl($downloadUrl, $baseUrl), $this->headers());

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return [
                ...$this->resultMetadata($item),
                'status' => 'failed',
                'http_status' => $response['status'],
            ];
        }

        $metadata = $this->resultMetadata($item);
        $record = $this->cache?->put($key, $response['body'], $metadata) ?? $metadata;

        return [
            ...$metadata,
            ...$record,
            'status' => 'downloaded',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<int, array<string, mixed>>
     */
    public function resolveAndCache(array $refs, ?string $baseUrl = null): array
    {
        $manifest = $this->manifest($refs, $baseUrl);
        if (($manifest['status'] ?? null) !== 'ok') {
            return [[
                'status' => 'failed',
                'error' => 'manifest_failed',
                'http_status' => $manifest['http_status'] ?? null,
            ]];
        }

        $payload = is_array($manifest['payload'] ?? null) ? $manifest['payload'] : [];
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return array_map(fn (array $item): array => $this->fetchAndCache($item, $baseUrl), $items);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function cacheKey(array $item): string
    {
        return implode(':', [
            $item['source_hub_id'] ?? 'local',
            $item['kind'] ?? 'media',
            $item['media_id'] ?? $item['attachment_id'] ?? $item['id'] ?? 'unknown',
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resultMetadata(array $item): array
    {
        return [
            'kind' => $item['kind'] ?? null,
            'source_hub_id' => $item['source_hub_id'] ?? null,
            'incident_id' => $item['incident_id'] ?? null,
            'media_id' => $item['media_id'] ?? null,
            'attachment_id' => $item['attachment_id'] ?? null,
            'mime_type' => $item['mime_type'] ?? null,
            'original_filename' => $item['original_filename'] ?? null,
            'source_metadata' => $item['source_metadata'] ?? [],
        ];
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        return array_filter([
            'Accept' => 'application/json',
            'X-Hotline-Media-Key' => (string) ($this->config['token'] ?? ''),
            'X-PBB-Source-System' => (string) ($this->config['source_system'] ?? ''),
            'X-PBB-Source-Hub-Id' => (string) ($this->config['source_hub_id'] ?? ''),
            ...$extra,
        ], static fn (string $value): bool => $value !== '');
    }

    private function url(string $path, ?string $baseUrl = null): string
    {
        return rtrim($baseUrl ?? (string) ($this->config['base_url'] ?? ''), '/').$path;
    }

    private function absoluteUrl(string $url, ?string $baseUrl = null): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        return $this->url('/'.ltrim($url, '/'), $baseUrl);
    }
}
