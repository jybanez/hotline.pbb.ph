<?php

namespace App\Support\Sitreps;

final class SitrepPayloadSchema
{
    public const VERSION = 2;

    /**
     * @param array<string, mixed> $section
     * @param array<string, mixed> $location
     * @return array{rollup: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public static function wrapSection(array $section, array $location): array
    {
        if (isset($section['rollup']) && is_array($section['rollup']) && isset($section['items']) && is_array($section['items'])) {
            return [
                'rollup' => $section['rollup'],
                'items' => $section['items'],
            ];
        }

        return [
            'rollup' => $section,
            'items' => [[
                'location' => $location,
                'data' => $section,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $sourceSnapshot
     * @return array<string, mixed>
     */
    public static function withHubNodes(array $sourceSnapshot): array
    {
        $hubNodes = isset($sourceSnapshot['hub_nodes']) && is_array($sourceSnapshot['hub_nodes'])
            ? array_values(array_filter($sourceSnapshot['hub_nodes'], 'is_array'))
            : [];

        $sourceSnapshot['hub_nodes'] = $hubNodes;

        return $sourceSnapshot;
    }

    /**
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    public static function rollup(array $section): array
    {
        return isset($section['rollup']) && is_array($section['rollup'])
            ? $section['rollup']
            : $section;
    }

    /**
     * @param array<string, mixed> $sourceSnapshot
     * @return array<string, mixed>
     */
    public static function locationFromSourceSnapshot(array $sourceSnapshot): array
    {
        $snapshot = $sourceSnapshot['hub_node']['snapshot'] ?? [];
        $snapshot = is_array($snapshot) ? $snapshot : [];

        return [
            'id' => $snapshot['hub_id'] ?? $snapshot['relay_hub_id'] ?? null,
            'name' => $snapshot['name'] ?? null,
            'deployment' => $snapshot['deployment'] ?? null,
            'relay_hub_id' => $snapshot['relay_hub_id'] ?? null,
        ];
    }
}
