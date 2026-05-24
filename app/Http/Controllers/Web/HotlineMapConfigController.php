<?php

namespace App\Http\Controllers\Web;

use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;

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
        );

        return response()->json([
            'map' => [
                'enabled' => true,
                'provider' => 'maplibre',
                'theme' => 'dark',
                'styleUrl' => '/maps/operator-vector-style.json',
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
            ],
        ]);
    }

    private function normalizeBaseUrl(mixed $value): string
    {
        $url = trim((string) $value);

        if ($url === '') {
            $url = 'https://mapserver.pbb.ph';
        }

        return rtrim($url, '/');
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
