<?php

namespace Pbb\Hotline\Media;

final class SitrepMediaRefResolver
{
    /**
     * @param  array<string, mixed>  $sitrep
     * @return array<int, array<string, mixed>>
     */
    public function extractMediaRefs(array $sitrep): array
    {
        $sourceSnapshot = $this->sourceSnapshot($sitrep);
        $rollup = $this->rollup($sourceSnapshot);
        $refs = [];

        $this->appendRefs($refs, $rollup['media_refs'] ?? [], $this->hubIdFromNode($rollup['hub_node'] ?? []));
        $this->appendRefs($refs, $sourceSnapshot['media_refs'] ?? [], $this->hubIdFromNode($sourceSnapshot['hub_node'] ?? []));

        foreach (($sourceSnapshot['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $data = is_array($item['data'] ?? null) ? $item['data'] : [];
            $location = is_array($item['location'] ?? null) ? $item['location'] : [];
            $sourceHubId = $this->hubIdFromNode($data['hub_node'] ?? []) ?? $this->text($location['id'] ?? null);
            $this->appendRefs($refs, $data['media_refs'] ?? [], $sourceHubId);
        }

        return array_values($refs);
    }

    /**
     * @param  array<string, mixed>  $sitrep
     * @return array<string, string>
     */
    public function resolveSourceHubs(array $sitrep): array
    {
        $sourceSnapshot = $this->sourceSnapshot($sitrep);
        $rollup = $this->rollup($sourceSnapshot);
        $hubs = [];

        $this->appendHub($hubs, $rollup['hub_node'] ?? []);
        foreach (($rollup['hub_nodes'] ?? []) as $node) {
            $this->appendHub($hubs, $node);
        }

        foreach (($sourceSnapshot['items'] ?? []) as $item) {
            $data = is_array($item['data'] ?? null) ? $item['data'] : [];
            $this->appendHub($hubs, $data['hub_node'] ?? []);
        }

        return $hubs;
    }

    /**
     * @param  array<string, mixed>  $sitrep
     * @return array<string, mixed>
     */
    private function sourceSnapshot(array $sitrep): array
    {
        $section = $sitrep['source_snapshot'] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param  array<string, mixed>  $sourceSnapshot
     * @return array<string, mixed>
     */
    private function rollup(array $sourceSnapshot): array
    {
        return is_array($sourceSnapshot['rollup'] ?? null) ? $sourceSnapshot['rollup'] : $sourceSnapshot;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     */
    private function appendRefs(array &$refs, mixed $items, ?string $sourceHubId): void
    {
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! isset($item['source_hub_id']) && $sourceHubId !== null) {
                $item['source_hub_id'] = $sourceHubId;
            }

            $refs[] = $item;
        }
    }

    /**
     * @param  array<string, string>  $hubs
     */
    private function appendHub(array &$hubs, mixed $node): void
    {
        if (! is_array($node)) {
            return;
        }

        $snapshot = is_array($node['snapshot'] ?? null) ? $node['snapshot'] : $node;
        $hubId = $this->text($snapshot['hub_id'] ?? $snapshot['id'] ?? $snapshot['relay_hub_id'] ?? null);
        $baseUrl = $this->baseUrlFromSnapshot($snapshot);

        if ($hubId !== null && $baseUrl !== null) {
            $hubs[$hubId] = $baseUrl;
        }
    }

    private function hubIdFromNode(mixed $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        $snapshot = is_array($node['snapshot'] ?? null) ? $node['snapshot'] : $node;

        return $this->text($snapshot['hub_id'] ?? $snapshot['id'] ?? $snapshot['relay_hub_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function baseUrlFromSnapshot(array $snapshot): ?string
    {
        $value = $this->text($snapshot['hotline_url'] ?? $snapshot['base_url'] ?? $snapshot['app_url'] ?? $snapshot['url'] ?? null);

        if ($value === null) {
            $domain = $this->text($snapshot['domain'] ?? $snapshot['host'] ?? null);
            $value = $domain !== null ? $this->landingGatewayBaseUrl($domain) : null;
        }

        return $value !== null ? rtrim($value, '/') : null;
    }

    private function landingGatewayBaseUrl(string $domain): string
    {
        $baseUrl = preg_match('/^https?:\/\//i', $domain) === 1
            ? rtrim($domain, '/')
            : 'https://'.rtrim($domain, '/');

        if (preg_match('/\/hotline$/i', $baseUrl) === 1) {
            return $baseUrl;
        }

        return $baseUrl.'/hotline';
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
