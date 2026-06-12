<?php

namespace Pbb\Hotline\Media;

final class FilesystemMediaCache implements MediaCacheInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function get(string $key): ?array
    {
        $metadataPath = $this->metadataPath($key);

        if (! is_file($metadataPath)) {
            return null;
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true);

        return is_array($metadata) ? $metadata : null;
    }

    public function put(string $key, string $contents, array $metadata): array
    {
        $path = $this->mediaPath($key, $metadata);
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $contents);

        $record = [
            ...$metadata,
            'cache_key' => $key,
            'local_path' => $path,
            'cached_at' => gmdate(DATE_ATOM),
        ];

        file_put_contents($this->metadataPath($key), json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $record;
    }

    private function mediaPath(string $key, array $metadata): string
    {
        $filename = basename((string) ($metadata['original_filename'] ?? ''));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = str_replace(['/', '\\', ':'], '-', $key).'.bin';
        }

        return rtrim($this->basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.sha1($key).DIRECTORY_SEPARATOR.$filename;
    }

    private function metadataPath(string $key): string
    {
        return rtrim($this->basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.sha1($key).DIRECTORY_SEPARATOR.'metadata.json';
    }
}
