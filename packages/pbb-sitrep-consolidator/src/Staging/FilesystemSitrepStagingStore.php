<?php

namespace Pbb\Sitreps\Consolidation\Staging;

final class FilesystemSitrepStagingStore implements SitrepStagingStore
{
    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    public function stage(array $normalizedSitrep): array
    {
        $deployment = (string) $normalizedSitrep['source_deployment'];
        $sourceHubId = (string) $normalizedSitrep['source_hub_id'];
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
        $directory = $this->rootPath.DIRECTORY_SEPARATOR.$deployment;

        if (! is_dir($directory)) {
            return [];
        }

        $items = [];

        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            if (is_array($payload)) {
                $items[] = $payload;
            }
        }

        return $items;
    }

    public function forget(string $deployment, string $sourceHubId): void
    {
        $path = $this->rootPath.DIRECTORY_SEPARATOR.$deployment.DIRECTORY_SEPARATOR.$sourceHubId.'.json';

        if (is_file($path)) {
            unlink($path);
        }
    }
}
