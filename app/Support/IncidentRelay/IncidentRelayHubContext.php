<?php

namespace App\Support\IncidentRelay;

use App\Support\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IncidentRelayHubContext
{
    private const HUB_JSON_CACHE_KEY = 'pbb_hotline.relay_hub_json.last_successful_snapshot';

    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $relayUrl = rtrim(trim((string) $this->settings->get('relay_url', 'https://relay.pbb.ph')), '/');
        $hubJsonUrl = $relayUrl !== '' ? $relayUrl.'/hub.json' : 'https://relay.pbb.ph/hub.json';

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get($hubJsonUrl);

            if ($response->successful() && is_array($response->json())) {
                $snapshot = $response->json();
                Cache::put(self::HUB_JSON_CACHE_KEY, $snapshot, now()->addDays(7));

                return $snapshot;
            }
        } catch (\Throwable) {
            // Fall back to the last Relay hub snapshot captured by Hotline.
        }

        $cached = Cache::get(self::HUB_JSON_CACHE_KEY);

        return is_array($cached) ? $cached : [];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function source(array $snapshot): array
    {
        return [
            'hub_id' => $this->stringOrNull($snapshot['hub_id'] ?? $snapshot['id'] ?? null),
            'relay_hub_id' => $this->stringOrNull($snapshot['relay_hub_id'] ?? null),
            'hub_name' => $this->stringOrNull($snapshot['name'] ?? $snapshot['hub_name'] ?? null),
            'deployment' => $this->stringOrNull($snapshot['deployment'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{id: string, systems: array<int, string>}>
     */
    public function targets(array $snapshot): array
    {
        $uplinks = is_array($snapshot['uplinks'] ?? null) ? $snapshot['uplinks'] : [];
        $systems = $this->targetSystems();
        $targets = [];

        foreach ($uplinks as $uplink) {
            if (! is_array($uplink) || ! is_array($uplink['hub'] ?? null)) {
                continue;
            }

            $id = $this->stringOrNull($uplink['hub']['id'] ?? null);

            if ($id === null || isset($targets[$id])) {
                continue;
            }

            $targets[$id] = [
                'id' => $id,
                'systems' => $systems,
            ];
        }

        if ($targets === []) {
            throw new \InvalidArgumentException('Relay target hubs are not available from hub.json uplinks.');
        }

        return array_values($targets);
    }

    /**
     * @return array<int, string>
     */
    public function targetSystems(): array
    {
        $configured = (string) $this->settings->get('incident_relay_target_systems', 'utility.vena');
        $targets = preg_split('/[\s,]+/', $configured) ?: [];
        $targets = array_values(array_unique(array_filter(array_map(
            fn (string $target): string => trim($target),
            $targets,
        ), fn (string $target): bool => $target !== '')));

        return $targets !== [] ? $targets : ['utility.vena'];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
