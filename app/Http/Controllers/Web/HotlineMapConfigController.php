<?php

namespace App\Http\Controllers\Web;

use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class HotlineMapConfigController
{
    private const DEFAULT_EXCLUDED_POI_CLASSES = [
        'gate',
        'parking',
        'parking_entrance',
        'car_parking',
        'bicycle_parking',
        'motorcycle_parking',
        'lift_gate',
        'pitch',
        'post',
        'swimming_pool',
        'office',
        'shelter',
        'bench',
        'toilets',
        'waste_basket',
        'recycling',
        'drinking_water',
        'vending_machine',
    ];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function show(): JsonResponse
    {
        $mapServerUrl = $this->normalizeBaseUrl(
            $this->settings->get('map_server_url', 'https://mapserver.pbb.ph'),
            'https://mapserver.pbb.ph',
        );
        $relayUrl = $this->normalizeBaseUrl(
            $this->settings->get('relay_url', 'https://relay.pbb.ph'),
            'https://relay.pbb.ph',
        );
        $boundary = $this->boundaryConfig($mapServerUrl, $relayUrl);

        return response()->json([
            'map' => [
                'enabled' => true,
                'provider' => 'maplibre',
                'theme' => 'dark',
                'styleUrl' => '/maps/operator-vector-style.json',
                'mapServerUrl' => $mapServerUrl,
                'center' => [123.8854, 10.3157],
                'zoom' => 12,
                'minZoom' => 8,
                'maxZoom' => 18,
                'assets' => [
                    'script' => '/vendor/maplibre/maplibre-gl.js',
                    'css' => '/vendor/maplibre/maplibre-gl.css',
                ],
                'tiles' => [
                    'vector' => $mapServerUrl.'/tiles/vector/{z}/{x}/{y}.pbf',
                    'terrain' => $mapServerUrl.'/tiles/terrain/{z}/{x}/{y}.png',
                    'glyphs' => $mapServerUrl.'/tiles/glyphs/{fontstack}/{range}.pbf',
                    'poi' => $mapServerUrl.'/tiles/poi/{z}/{x}/{y}.pbf',
                ],
                'poi' => [
                    'enabled' => true,
                    'sourceLayers' => ['poi', 'pois', 'point', 'points', 'amenity'],
                    'excludedClasses' => $this->excludedPoiClasses(),
                ],
                'boundary' => $boundary,
            ],
        ]);
    }

    private function normalizeBaseUrl(mixed $value, string $fallback): string
    {
        $url = trim((string) $value);

        if ($url === '') {
            $url = $fallback;
        }

        $url = rtrim($url, '/');
        $host = parse_url($url, PHP_URL_HOST);

        if (! app()->environment('local') && is_string($host) && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return rtrim($fallback, '/');
        }

        return $url;
    }

    /**
     * @return array{enabled: bool, url?: string, scope?: string, code?: string, source?: string}
     */
    private function boundaryConfig(string $mapServerUrl, string $relayUrl): array
    {
        $hub = $this->relayHubSnapshot($relayUrl);
        $boundary = is_array($hub) ? $this->boundaryReference($hub) : null;

        if ($boundary === null) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'url' => sprintf(
                '%s/boundaries/%s/%s.geojson',
                $mapServerUrl,
                rawurlencode($boundary['scope']),
                rawurlencode($boundary['code']),
            ),
            'scope' => $boundary['scope'],
            'code' => $boundary['code'],
            'source' => $relayUrl.'/hub.json',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function relayHubSnapshot(string $relayUrl): ?array
    {
        try {
            $request = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4);

            $caBundle = $this->configuredCaBundle();
            if ($caBundle !== null) {
                $request = $request->withOptions(['verify' => $caBundle]);
            }

            $response = $request->get($relayUrl.'/hub.json');
        } catch (\Throwable) {
            return null;
        }

        $payload = $response->successful() ? $response->json() : null;

        return is_array($payload) ? $payload : null;
    }

    private function configuredCaBundle(): ?string
    {
        $path = trim((string) config('services.realtime_publish.ca_bundle', ''));

        return $path !== '' && is_file($path) ? $path : null;
    }

    /**
     * @param array<string, mixed> $hub
     * @return array{scope: string, code: string}|null
     */
    private function boundaryReference(array $hub): ?array
    {
        $deployment = strtolower(trim((string) ($hub['deployment'] ?? '')));
        $scope = in_array($deployment, ['barangay', 'city', 'province', 'region'], true)
            ? $deployment
            : 'barangay';

        $code = match ($scope) {
            'city' => $hub['citymun_code'] ?? null,
            'province' => $hub['prov_code'] ?? null,
            'region' => $hub['reg_code'] ?? null,
            default => $hub['brgy_code'] ?? $hub['relay_hub_id'] ?? null,
        };

        $code = trim((string) $code);

        return $code === '' ? null : ['scope' => $scope, 'code' => $code];
    }

    /**
     * @return array<int, string>
     */
    private function excludedPoiClasses(): array
    {
        $configured = trim((string) $this->settings->get('excluded_poi_classes', ''));

        if ($configured === '') {
            return self::DEFAULT_EXCLUDED_POI_CLASSES;
        }

        return collect(explode(',', $configured))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all();
    }
}
