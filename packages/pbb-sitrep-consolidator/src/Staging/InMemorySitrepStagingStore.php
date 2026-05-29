<?php

namespace Pbb\Sitreps\Consolidation\Staging;

final class InMemorySitrepStagingStore implements SitrepStagingStore
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $items = [];

    public function stage(array $normalizedSitrep): array
    {
        $deployment = (string) $normalizedSitrep['source_deployment'];
        $sourceHubId = (string) $normalizedSitrep['source_hub_id'];

        $this->items[$deployment][$sourceHubId] = $normalizedSitrep;

        return [
            'deployment' => $deployment,
            'source_hub_id' => $sourceHubId,
            'key' => sprintf('%s/%s.json', $deployment, $sourceHubId),
            'sitrep' => $normalizedSitrep,
        ];
    }

    public function list(string $deployment): array
    {
        return array_values($this->items[$deployment] ?? []);
    }

    public function forget(string $deployment, string $sourceHubId): void
    {
        unset($this->items[$deployment][$sourceHubId]);
    }
}
