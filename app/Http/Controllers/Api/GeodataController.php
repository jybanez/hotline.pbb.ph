<?php

namespace App\Http\Controllers\Api;

use App\Models\GeoBarangay;
use App\Models\GeoCity;
use App\Models\GeoProvince;
use App\Models\GeoRegion;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GeodataController extends BaseApiController
{
    public function regions(Request $request)
    {
        $params = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->paged(
            GeoRegion::query()
                ->select(['id', 'regCode', 'name', 'code', 'area', 'areaName', 'islandGroup', 'areaGroup'])
                ->orderBy('regCode'),
            'geodata:regions',
            (int) ($params['limit'] ?? 50),
            (int) ($params['offset'] ?? 0)
        );
    }

    public function provinces(Request $request)
    {
        $params = $request->validate([
            'regCode' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = GeoProvince::query()
            ->select(['id', 'provCode', 'name', 'regCode', 'lat', 'lon'])
            ->orderBy('name');

        $cachePrefix = 'geodata:provinces:all';
        if (!empty($params['regCode'])) {
            $query->where('regCode', $params['regCode']);
            $cachePrefix = 'geodata:provinces:'.$params['regCode'];
        }

        return $this->paged(
            $query,
            $cachePrefix,
            (int) ($params['limit'] ?? 50),
            (int) ($params['offset'] ?? 0)
        );
    }

    public function cities(Request $request)
    {
        $params = $request->validate([
            'provCode' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = GeoCity::query()
            ->select(['id', 'citymunCode', 'name', 'provCode', 'regCode', 'lat', 'lon'])
            ->orderBy('name');

        $cachePrefix = 'geodata:cities:all';
        if (!empty($params['provCode'])) {
            $query->where('provCode', $params['provCode']);
            $cachePrefix = 'geodata:cities:'.$params['provCode'];
        }

        return $this->paged(
            $query,
            $cachePrefix,
            (int) ($params['limit'] ?? 50),
            (int) ($params['offset'] ?? 0)
        );
    }

    public function barangays(Request $request)
    {
        $params = $request->validate([
            'citymunCode' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = GeoBarangay::query()
            ->select(['id', 'brgyCode', 'name', 'citymunCode', 'provCode', 'regCode', 'lat', 'lon'])
            ->orderBy('name');

        $cachePrefix = 'geodata:barangays:all';
        if (!empty($params['citymunCode'])) {
            $query->where('citymunCode', $params['citymunCode']);
            $cachePrefix = 'geodata:barangays:'.$params['citymunCode'];
        }

        return $this->paged(
            $query,
            $cachePrefix,
            (int) ($params['limit'] ?? 50),
            (int) ($params['offset'] ?? 0)
        );
    }

    public function updateProvinceCoordinates(Request $request)
    {
        $params = $request->validate([
            'provCode' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $province = GeoProvince::query()
            ->where('provCode', $params['provCode'])
            ->firstOrFail();

        $province->lat = $params['lat'];
        $province->lon = $params['lon'];
        $province->save();

        $this->bumpCacheVersion('geodata:provinces:'.$province->regCode);
        $this->bumpCacheVersion('geodata:provinces:all');

        return $this->ok([
            'id' => $province->id,
            'provCode' => $province->provCode,
            'name' => $province->name,
            'regCode' => $province->regCode,
            'lat' => $province->lat,
            'lon' => $province->lon,
        ]);
    }

    public function updateCityCoordinates(Request $request)
    {
        $params = $request->validate([
            'citymunCode' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $city = GeoCity::query()
            ->where('citymunCode', $params['citymunCode'])
            ->firstOrFail();

        $city->lat = $params['lat'];
        $city->lon = $params['lon'];
        $city->save();

        $this->bumpCacheVersion('geodata:cities:'.$city->provCode);
        $this->bumpCacheVersion('geodata:cities:all');

        return $this->ok([
            'id' => $city->id,
            'citymunCode' => $city->citymunCode,
            'name' => $city->name,
            'provCode' => $city->provCode,
            'regCode' => $city->regCode,
            'lat' => $city->lat,
            'lon' => $city->lon,
        ]);
    }

    public function updateBarangayCoordinates(Request $request)
    {
        $params = $request->validate([
            'brgyCode' => ['required', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $barangay = GeoBarangay::query()
            ->where('brgyCode', $params['brgyCode'])
            ->firstOrFail();

        $barangay->lat = $params['lat'];
        $barangay->lon = $params['lon'];
        $barangay->save();

        $this->bumpCacheVersion('geodata:barangays:'.$barangay->citymunCode);
        $this->bumpCacheVersion('geodata:barangays:all');

        return $this->ok([
            'id' => $barangay->id,
            'brgyCode' => $barangay->brgyCode,
            'name' => $barangay->name,
            'citymunCode' => $barangay->citymunCode,
            'provCode' => $barangay->provCode,
            'regCode' => $barangay->regCode,
            'lat' => $barangay->lat,
            'lon' => $barangay->lon,
        ]);
    }

    private function paged(Builder $baseQuery, string $cachePrefix, int $limit, int $offset)
    {
        $total = (clone $baseQuery)->count();
        $version = $this->cacheVersion($cachePrefix);
        $key = "{$cachePrefix}:v:{$version}:limit:{$limit}:offset:{$offset}";
        $cacheHit = true;
        $data = $this->cacheStore()->get($key);

        if ($data === null) {
            $cacheHit = false;
            $data = (clone $baseQuery)->offset($offset)->limit($limit)->get()->toArray();
            $this->cacheStore()->forever($key, $data);
        }

        $loaded = min($offset + $limit, $total);

        return $this->ok(
            $data,
            [
                'total' => $total,
                'loaded' => $loaded,
                'has_more' => $loaded < $total,
                'next_offset' => $loaded < $total ? $loaded : null,
                'limit' => $limit,
                'offset' => $offset,
            ],
            200,
            [
                'X-Cache' => $cacheHit ? 'HIT' : 'MISS',
                'X-Cache-Store' => 'file_data_api',
                'X-Cache-Key' => $key,
                'X-Cache-Version' => (string) $version,
            ]
        );
    }

    private function cacheVersion(string $cachePrefix): int
    {
        return (int) $this->cacheStore()->get($this->cacheVersionKey($cachePrefix), 1);
    }

    private function bumpCacheVersion(string $cachePrefix): void
    {
        $versionKey = $this->cacheVersionKey($cachePrefix);
        $this->cacheStore()->forever($versionKey, $this->cacheVersion($cachePrefix) + 1);
    }

    private function cacheVersionKey(string $cachePrefix): string
    {
        return "{$cachePrefix}:version";
    }

    private function cacheStore()
    {
        return Cache::store('file_data_api');
    }
}
