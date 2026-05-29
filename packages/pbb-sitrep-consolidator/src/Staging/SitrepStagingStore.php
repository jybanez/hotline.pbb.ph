<?php

namespace Pbb\Sitreps\Consolidation\Staging;

interface SitrepStagingStore
{
    /**
     * @param array<string, mixed> $normalizedSitrep
     * @return array<string, mixed>
     */
    public function stage(array $normalizedSitrep): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $deployment): array;

    public function forget(string $deployment, string $sourceHubId): void;
}
