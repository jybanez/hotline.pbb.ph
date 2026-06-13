<?php

namespace Pbb\Hotline\Media;

final class MediaRef
{
    private readonly MediaRefLocalUrl $localUrl;
    private readonly MediaCacheInterface $cache;

    /**
     * @param  array<string, mixed>  $ref
     * @param  array{base_url?:string,source_hubs?:array<string, string>,token?:string,relay_token?:string,source_system?:string,source_hub_id?:string}  $config
     */
    public function __construct(
        private readonly array $ref,
        string|MediaCacheInterface $cache,
        private readonly array $config = [],
        private readonly ?HttpTransportInterface $transport = null,
    ) {
        $this->localUrl = new MediaRefLocalUrl();
        $this->cache = is_string($cache) ? new FilesystemMediaCache($cache) : $cache;
    }

    public function localPath(string $prefix = '/media'): ?string
    {
        return $this->localUrl->path($this->ref, $prefix);
    }

    public function cacheKey(): ?string
    {
        return $this->localUrl->cacheKey($this->ref);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $cacheKey = $this->cacheKey();
        if ($cacheKey === null) {
            return [
                'status' => 'failed',
                'error' => 'invalid_media_ref',
            ];
        }

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return [
                ...$this->metadata(),
                ...$cached,
                'status' => 'cached',
            ];
        }

        $relationship = $this->relationship();
        $baseUrl = $relationship['source_base_url'] ?? null;
        $token = $relationship['token'] ?? $this->token();
        $sourceHubId = $relationship['local_hub_id'] ?? (string) ($this->config['source_hub_id'] ?? getenv('PBB_SOURCE_HUB_ID') ?: '');

        if ($baseUrl === null) {
            return [
                ...$this->metadata(),
                'status' => 'failed',
                'error' => 'missing_source_base_url',
            ];
        }

        $client = new HotlineMediaClient([
            'base_url' => $baseUrl,
            'token' => $token,
            'source_system' => (string) ($this->config['source_system'] ?? getenv('PBB_SOURCE_SYSTEM') ?: ''),
            'source_hub_id' => $sourceHubId,
        ], $this->cache, $this->transport);

        $manifest = $client->manifest([$this->ref], $baseUrl);
        if (($manifest['status'] ?? null) !== 'ok') {
            return [
                ...$this->metadata(),
                'status' => 'failed',
                'error' => 'manifest_failed',
                'http_status' => $manifest['http_status'] ?? null,
            ];
        }

        $payload = is_array($manifest['payload'] ?? null) ? $manifest['payload'] : [];
        $items = array_values(array_filter(
            is_array($payload['items'] ?? null) ? $payload['items'] : [],
            'is_array',
        ));

        if ($items === []) {
            $unavailable = is_array($payload['unavailable'] ?? null) ? $payload['unavailable'] : [];

            return [
                ...$this->metadata(),
                'status' => 'failed',
                'error' => 'media_unavailable',
                'unavailable' => $unavailable,
            ];
        }

        return $client->fetchAndCache($items[0], $baseUrl);
    }

    /**
     * Sends basic headers and streams the resolved local file when running in a PHP SAPI.
     *
     * @return array<string, mixed>
     */
    public function serve(): array
    {
        $result = $this->resolve();
        $path = $this->text($result['local_path'] ?? null);

        if (($result['status'] ?? null) === 'failed' || $path === null || ! is_file($path)) {
            if (! headers_sent()) {
                http_response_code(404);
                header('Content-Type: application/json');
            }

            echo json_encode([
                'ok' => false,
                'status' => $result['status'] ?? 'failed',
                'error' => $result['error'] ?? 'media_not_available',
            ], JSON_UNESCAPED_SLASHES);

            return $result;
        }

        if (! headers_sent()) {
            header('Content-Type: '.($this->text($result['mime_type'] ?? null) ?? 'application/octet-stream'));
            header('Content-Length: '.filesize($path));
            header('X-Hotline-Media-Cache: '.($result['status'] === 'cached' ? 'hit' : 'miss'));
        }

        readfile($path);

        return $result;
    }

    /**
     * @return array{source_base_url?:string,token?:string,local_hub_id?:string}
     */
    private function relationship(): array
    {
        $sourceHubId = $this->text($this->ref['source_hub_id'] ?? null);
        $sourceHubs = is_array($this->config['source_hubs'] ?? null) ? $this->config['source_hubs'] : [];
        $directBaseUrl = $this->text($this->config['base_url'] ?? null)
            ?? ($sourceHubId !== null ? $this->text($sourceHubs[$sourceHubId] ?? null) : null)
            ?? $this->text($this->ref['source_base_url'] ?? $this->ref['source_hotline_url'] ?? $this->ref['hotline_url'] ?? $this->ref['base_url'] ?? null);

        if ($directBaseUrl !== null) {
            return [
                'source_base_url' => $directBaseUrl,
                'token' => $this->token(),
            ];
        }

        $relayToken = $this->text($this->config['relay_token'] ?? getenv('RELAY_TOKEN') ?: null);
        if ($sourceHubId !== null && $relayToken !== null) {
            return (new RelayRelationshipResolver($this->transport ?? new NativeHttpTransport()))->resolve($sourceHubId, $relayToken);
        }

        return [];
    }

    private function token(): string
    {
        return (string) ($this->config['token'] ?? getenv('HOTLINE_MEDIA_ACCESS_TOKEN') ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        return [
            'kind' => $this->ref['kind'] ?? null,
            'source_hub_id' => $this->ref['source_hub_id'] ?? null,
            'incident_id' => $this->ref['incident_id'] ?? null,
            'media_id' => $this->ref['media_id'] ?? null,
            'message_id' => $this->ref['message_id'] ?? null,
            'attachment_id' => $this->ref['attachment_id'] ?? null,
            'mime_type' => $this->ref['mime_type'] ?? null,
            'original_filename' => $this->ref['original_filename'] ?? null,
        ];
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
