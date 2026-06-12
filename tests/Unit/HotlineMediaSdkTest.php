<?php

namespace Tests\Unit;

use Pbb\Hotline\Media\HotlineMediaClient;
use Pbb\Hotline\Media\HttpTransportInterface;
use Pbb\Hotline\Media\MediaCacheInterface;
use Pbb\Hotline\Media\SitrepMediaRefResolver;
use PHPUnit\Framework\TestCase;

class HotlineMediaSdkTest extends TestCase
{
    public function test_resolver_extracts_media_refs_from_direct_sitrep(): void
    {
        $resolver = new SitrepMediaRefResolver;
        $sitrep = [
            'source_snapshot' => [
                'rollup' => [
                    'hub_node' => [
                        'snapshot' => [
                            'hub_id' => 'hub-1',
                            'hotline_url' => 'https://hotline-source.test',
                        ],
                    ],
                    'media_refs' => [[
                        'kind' => 'incident_media',
                        'incident_id' => 10,
                        'media_id' => 501,
                    ]],
                ],
            ],
        ];

        $refs = $resolver->extractMediaRefs($sitrep);

        $this->assertCount(1, $refs);
        $this->assertSame('hub-1', $refs[0]['source_hub_id']);
        $this->assertSame(501, $refs[0]['media_id']);
    }

    public function test_resolver_extracts_media_refs_from_consolidated_multi_source_sitrep(): void
    {
        $resolver = new SitrepMediaRefResolver;
        $sitrep = [
            'source_snapshot' => [
                'rollup' => [
                    'hub_node' => ['snapshot' => ['hub_id' => 'city', 'hotline_url' => 'https://city.test']],
                    'hub_nodes' => [
                        ['snapshot' => ['hub_id' => 'hub-1', 'domain' => 'hub-1.test']],
                        ['snapshot' => ['hub_id' => 'hub-2', 'base_url' => 'https://hub-2.test']],
                    ],
                    'media_refs' => [
                        ['kind' => 'incident_media', 'source_hub_id' => 'hub-1', 'incident_id' => 10, 'media_id' => 501],
                        ['kind' => 'message_attachment', 'source_hub_id' => 'hub-2', 'incident_id' => 20, 'attachment_id' => 601],
                    ],
                ],
                'items' => [
                    [
                        'data' => [
                            'hub_node' => ['snapshot' => ['hub_id' => 'hub-3', 'url' => 'https://hub-3.test']],
                            'media_refs' => [
                                ['kind' => 'incident_media', 'incident_id' => 30, 'media_id' => 701],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $refs = $resolver->extractMediaRefs($sitrep);
        $hubs = $resolver->resolveSourceHubs($sitrep);

        $this->assertSame(['hub-1', 'hub-2', 'hub-3'], array_column($refs, 'source_hub_id'));
        $this->assertSame('https://hub-1.test', $hubs['hub-1']);
        $this->assertSame('https://hub-2.test', $hubs['hub-2']);
        $this->assertSame('https://hub-3.test', $hubs['hub-3']);
    }

    public function test_resolver_handles_missing_media_refs(): void
    {
        $this->assertSame([], (new SitrepMediaRefResolver)->extractMediaRefs([
            'source_snapshot' => ['rollup' => []],
        ]));
    }

    public function test_client_uses_cache_hit_without_download(): void
    {
        $cache = new FakeMediaCache([
            'hub-1:incident_media:501' => [
                'local_path' => '/cache/operator-audio.webm',
                'mime_type' => 'audio/webm',
            ],
        ]);
        $transport = new FakeTransport;
        $client = new HotlineMediaClient(['base_url' => 'https://source.test', 'token' => 'secret'], $cache, $transport);

        $result = $client->fetchAndCache([
            'kind' => 'incident_media',
            'source_hub_id' => 'hub-1',
            'media_id' => 501,
            'download_url' => 'https://source.test/api/internal/sitrep/media/incident_media/501',
        ]);

        $this->assertSame('cached', $result['status']);
        $this->assertSame('/cache/operator-audio.webm', $result['local_path']);
        $this->assertSame(0, $transport->calls);
    }

    public function test_client_fetches_and_caches_on_miss(): void
    {
        $cache = new FakeMediaCache;
        $transport = new FakeTransport([
            'GET https://source.test/api/internal/sitrep/media/incident_media/501' => [
                'status' => 200,
                'headers' => ['content-type' => 'audio/webm'],
                'body' => 'media-bytes',
            ],
        ]);
        $client = new HotlineMediaClient(['base_url' => 'https://source.test', 'token' => 'secret'], $cache, $transport);

        $result = $client->fetchAndCache([
            'kind' => 'incident_media',
            'source_hub_id' => 'hub-1',
            'incident_id' => 10,
            'media_id' => 501,
            'mime_type' => 'audio/webm',
            'original_filename' => 'operator-audio.webm',
            'download_url' => 'https://source.test/api/internal/sitrep/media/incident_media/501',
        ]);

        $this->assertSame('downloaded', $result['status']);
        $this->assertSame('media-bytes', $cache->contents['hub-1:incident_media:501']);
        $this->assertSame('operator-audio.webm', $result['original_filename']);
    }
}

final class FakeMediaCache implements MediaCacheInterface
{
    /**
     * @param  array<string, array<string, mixed>>  $records
     */
    public function __construct(
        public array $records = [],
        public array $contents = [],
    ) {}

    public function get(string $key): ?array
    {
        return $this->records[$key] ?? null;
    }

    public function put(string $key, string $contents, array $metadata): array
    {
        $this->contents[$key] = $contents;
        $this->records[$key] = [
            ...$metadata,
            'local_path' => '/fake-cache/'.sha1($key),
        ];

        return $this->records[$key];
    }
}

final class FakeTransport implements HttpTransportInterface
{
    public int $calls = 0;

    /**
     * @param  array<string, array{status:int,headers:array<string, string>,body:string}>  $responses
     */
    public function __construct(private readonly array $responses = []) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->calls++;

        return $this->responses[strtoupper($method).' '.$url] ?? [
            'status' => 404,
            'headers' => [],
            'body' => '',
        ];
    }
}
