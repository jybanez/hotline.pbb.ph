<?php

namespace Pbb\Hotline\Media;

interface MediaCacheInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function put(string $key, string $contents, array $metadata): array;
}
