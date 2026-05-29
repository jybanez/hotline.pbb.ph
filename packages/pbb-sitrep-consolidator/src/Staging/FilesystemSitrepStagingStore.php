<?php

namespace Pbb\Sitreps\Consolidation\Staging;

use Pbb\Sitreps\Consolidation\SitrepNormalizer;

final class FilesystemSitrepStagingStore implements SitrepStagingStore
{
    public function __construct(
        private readonly string $rootPath,
        private readonly SitrepNormalizer $normalizer = new SitrepNormalizer(),
    ) {
    }

    public function stage(array $normalizedSitrep): array
    {
        $deployment = $this->safePathSegment((string) $normalizedSitrep['source_deployment'], 'source_deployment');
        $sourceHubId = $this->safePathSegment((string) $normalizedSitrep['source_hub_id'], 'source_hub_id');
        $directory = $this->rootPath.DIRECTORY_SEPARATOR.$deployment;
        $path = $directory.DIRECTORY_SEPARATOR.$sourceHubId.'.json';

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create staging directory: %s', $directory));
        }

        file_put_contents($path, json_encode($normalizedSitrep['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'deployment' => $deployment,
            'source_hub_id' => $sourceHubId,
            'key' => sprintf('%s/%s.json', $deployment, $sourceHubId),
            'path' => $path,
            'sitrep' => $normalizedSitrep,
        ];
    }

    public function list(string $deployment): array
    {
        $safeDeployment = $this->safePathSegment($deployment, 'deployment');
        $directory = $this->rootPath.DIRECTORY_SEPARATOR.$safeDeployment;

        if (! is_dir($directory)) {
            return [];
        }

        $items = [];

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            if (is_array($payload)) {
                $normalized = $this->normalizer->normalize($payload);

                if ($normalized['normalized'] !== null) {
                    $items[] = $normalized['normalized'];
                }
            }
        }

        return $items;
    }

    public function forget(string $deployment, string $sourceHubId): void
    {
        $path = $this->rootPath
            .DIRECTORY_SEPARATOR.$this->safePathSegment($deployment, 'deployment')
            .DIRECTORY_SEPARATOR.$this->safePathSegment($sourceHubId, 'source_hub_id').'.json';

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function safePathSegment(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/\A[A-Za-z0-9._-]+\z/', $value)) {
            throw new \InvalidArgumentException(sprintf('Unsafe %s path segment.', $field));
        }

        if ($value === '.' || $value === '..') {
            throw new \InvalidArgumentException(sprintf('Unsafe %s path segment.', $field));
        }

        return $value;
    }
}
