<?php

namespace Tests\Unit;

use Pbb\Hotline\Media\HotlineMediaClient;
use Pbb\Hotline\Media\HttpTransportInterface;
use Pbb\Hotline\Media\MediaRef;
use Pbb\Hotline\Media\MediaRefLocalUrl;
use Pbb\Hotline\Media\MediaCacheInterface;
use Pbb\Hotline\Media\RelayRelationshipResolver;
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
        $this->assertSame('https://hub-1.test/hotline', $hubs['hub-1']);
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
        $this->assertSame('media-bytes', $cache->contents['hub-1:10:incident_media:501']);
        $this->assertSame('operator-audio.webm', $result['original_filename']);
    }

    public function test_media_ref_resolve_uses_cache_before_manifest(): void
    {
        $cache = new FakeMediaCache([
            'hub-1:10:incident_media:501' => [
                'local_path' => '/cache/operator-audio.webm',
                'mime_type' => 'audio/webm',
            ],
        ]);
        $transport = new FakeTransport;
        $media = new MediaRef([
            'kind' => 'incident_media',
            'source_hub_id' => 'hub-1',
            'incident_id' => 10,
            'type' => 'audio_peer',
            'media_id' => 501,
            'source_base_url' => 'https://source.test',
        ], $cache, ['token' => 'secret'], $transport);

        $result = $media->resolve();

        $this->assertSame('cached', $result['status']);
        $this->assertSame('/cache/operator-audio.webm', $result['local_path']);
        $this->assertSame(0, $transport->calls);
    }

    public function test_media_ref_resolve_fetches_and_caches_on_miss(): void
    {
        $cache = new FakeMediaCache;
        $transport = new FakeTransport([
            'POST https://source.test/api/internal/sitrep/media/manifest' => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'ok' => true,
                    'items' => [[
                        'kind' => 'message_attachment',
                        'source_hub_id' => 'hub-1',
                        'incident_id' => 10,
                        'message_id' => 70,
                        'attachment_id' => 601,
                        'mime_type' => 'image/jpeg',
                        'original_filename' => 'scene.jpg',
                        'download_url' => '/api/internal/sitrep/media/message_attachment/601?incident_id=10&message_id=70',
                    ]],
                    'unavailable' => [],
                ], JSON_THROW_ON_ERROR),
            ],
            'GET https://source.test/api/internal/sitrep/media/message_attachment/601?incident_id=10&message_id=70' => [
                'status' => 200,
                'headers' => ['content-type' => 'image/jpeg'],
                'body' => 'image-bytes',
            ],
        ]);
        $media = new MediaRef([
            'kind' => 'message_attachment',
            'source_hub_id' => 'hub-1',
            'incident_id' => 10,
            'message_id' => 70,
            'attachment_id' => 601,
            'source_base_url' => 'https://source.test',
        ], $cache, ['token' => 'secret'], $transport);

        $result = $media->resolve();

        $this->assertSame('downloaded', $result['status']);
        $this->assertSame('image-bytes', $cache->contents['hub-1:10:message_attachment:70:601']);
        $this->assertSame('scene.jpg', $result['original_filename']);
        $this->assertSame(2, $transport->calls);
    }

    public function test_media_ref_resolve_can_use_relay_relationship_resolution(): void
    {
        $cache = new FakeMediaCache;
        $transport = new FakeTransport([
            'GET https://relay.pbb.ph/hub.json' => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode(['hub_id' => '11'], JSON_THROW_ON_ERROR),
            ],
            'POST https://relay.pbb.ph/api/v1/relationships/resolve' => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'valid' => true,
                    'source_hub_id' => '13',
                    'target_hub_id' => '11',
                    'domain' => 'apas-cebu-cebu.pbb.ph',
                    'auth_mode' => 'shared_key',
                    'token' => 'pbb_link_secret',
                    'version' => '1',
                ], JSON_THROW_ON_ERROR),
            ],
            'POST https://apas-cebu-cebu.pbb.ph/hotline/api/internal/sitrep/media/manifest' => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'ok' => true,
                    'items' => [[
                        'kind' => 'incident_media',
                        'source_hub_id' => '13',
                        'incident_id' => 593,
                        'media_id' => 501,
                        'type' => 'citizen_video',
                        'mime_type' => 'video/webm',
                        'original_filename' => 'clip.webm',
                        'download_url' => '/api/internal/sitrep/media/incident_media/501?incident_id=593',
                    ]],
                    'unavailable' => [],
                ], JSON_THROW_ON_ERROR),
            ],
            'GET https://apas-cebu-cebu.pbb.ph/hotline/api/internal/sitrep/media/incident_media/501?incident_id=593' => [
                'status' => 200,
                'headers' => ['content-type' => 'video/webm'],
                'body' => 'video-bytes',
            ],
        ]);
        $media = new MediaRef([
            'kind' => 'incident_media',
            'source_hub_id' => '13',
            'incident_id' => 593,
            'type' => 'citizen_video',
            'media_id' => 501,
        ], $cache, ['relay_token' => 'relay-client-token'], $transport);

        $result = $media->resolve();

        $this->assertSame('downloaded', $result['status']);
        $this->assertSame('video-bytes', $cache->contents['13:593:incident_media:501']);
        $this->assertSame(4, $transport->calls);
        $this->assertSame('X-Relay-Key: relay-client-token', $transport->requests[1]['headers']['X-Relay-Key'] ?? null);
        $this->assertSame('X-Hotline-Media-Key: pbb_link_secret', $transport->requests[2]['headers']['X-Hotline-Media-Key'] ?? null);
        $this->assertSame('X-PBB-Source-Hub-Id: 11', $transport->requests[2]['headers']['X-PBB-Source-Hub-Id'] ?? null);
    }

    public function test_relay_relationship_resolution_uses_landing_hotline_gateway_base_url(): void
    {
        $resolver = new RelayRelationshipResolver(new FakeTransport([
            'GET https://relay.pbb.ph/hub.json' => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode(['hub_id' => '11'], JSON_THROW_ON_ERROR),
            ],
            'POST https://relay.pbb.ph/api/v1/relationships/resolve' => [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'valid' => true,
                    'source_hub_domain' => 'apas-cebu-cebu.pbb.ph',
                    'token' => 'pbb_link_secret',
                ], JSON_THROW_ON_ERROR),
            ],
        ]));

        $result = $resolver->resolve('13', 'relay-client-token');

        $this->assertSame('https://apas-cebu-cebu.pbb.ph/hotline', $result['source_base_url']);
    }

    public function test_relay_relationship_resolution_fails_loudly_without_local_relay_hub(): void
    {
        $resolver = new RelayRelationshipResolver(new FakeTransport([
            'GET https://relay.pbb.ph/hub.json' => [
                'status' => 404,
                'headers' => ['content-type' => 'application/json'],
                'body' => '{}',
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve local PBB hub id from https://relay.pbb.ph/hub.json.');

        $resolver->resolve('13', 'relay-client-token');
    }

    public function test_local_url_helper_builds_incident_media_paths_and_cache_keys(): void
    {
        $helper = new MediaRefLocalUrl;
        $ref = [
            'kind' => 'incident_media',
            'source_hub_id' => 'hub 1',
            'incident_id' => 593,
            'type' => 'citizen video',
            'media_id' => 501,
        ];

        $this->assertSame('/media/hub%201/593/incident_media/citizen%20video/501', $helper->path($ref));
        $this->assertSame('hub 1:593:incident_media:501', $helper->cacheKey($ref));
    }

    public function test_local_url_helper_builds_message_attachment_paths_and_cache_keys(): void
    {
        $helper = new MediaRefLocalUrl;
        $ref = [
            'kind' => 'message_attachment',
            'source_hub_id' => '13',
            'incident_id' => 605,
            'message_id' => 701,
            'attachment_id' => 601,
        ];

        $this->assertSame('/media/13/605/message_attachment/701/601', $helper->path($ref));
        $this->assertSame('13:605:message_attachment:701:601', $helper->cacheKey($ref));
    }

    public function test_local_url_helper_rejects_incomplete_refs(): void
    {
        $helper = new MediaRefLocalUrl;

        $this->assertNull($helper->path([
            'kind' => 'incident_media',
            'source_hub_id' => '13',
            'incident_id' => 593,
            'media_id' => 501,
        ]));
        $this->assertNull($helper->cacheKey([
            'kind' => 'message_attachment',
            'source_hub_id' => '13',
            'incident_id' => 605,
            'attachment_id' => 601,
        ]));
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

    public array $requests = [];

    /**
     * @param  array<string, array{status:int,headers:array<string, string>,body:string}>  $responses
     */
    public function __construct(private readonly array $responses = []) {}

    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $this->calls++;
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[$name] = $name.': '.$value;
        }

        $this->requests[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headerLines,
            'body' => $body,
        ];

        return $this->responses[strtoupper($method).' '.$url] ?? [
            'status' => 404,
            'headers' => [],
            'body' => '',
        ];
    }
}
