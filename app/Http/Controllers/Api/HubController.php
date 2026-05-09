<?php

namespace App\Http\Controllers\Api;

use App\Models\Hub;
use App\Models\HubUplink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class HubController extends BaseApiController
{
    public function index()
    {
        $cached = $this->rememberHubsPayload('hubs:admin:index', [], function () {
            $hubs = Hub::query()
                ->with(['token', 'uplinks.uplinkHub:id,name,domain', 'downstreamUplinks.hub:id,name,domain'])
                ->orderBy('name')
                ->get()
                ->map(function (Hub $hub) {
                    return $this->toHubPayload($hub);
                })
                ->values();

            return [
                'hubs' => $hubs,
                'options' => [
                    'deployments' => $this->hubLevels(),
                    'statuses' => $this->statuses(),
                ],
            ];
        });

        return $this->ok($cached['data'], null, 200, $cached['headers']);
    }

    public function showIndex(Request $request)
    {
        $filters = $request->only(['deployment', 'status', 'code', 'domain']);

        $cached = $this->rememberHubsPayload('hubs:canonical:index', $filters, function () use ($request) {
            $query = Hub::query()
                ->with([
                    'token',
                    'uplinks.uplinkHub:id,name,code,deployment,domain,status',
                    'downstreamUplinks.hub:id,name,code,deployment,domain,status',
                ])
                ->orderBy('name');

            if ($request->filled('deployment')) {
                $query->where('deployment', $request->string('deployment')->toString());
            }

            if ($request->filled('status')) {
                $query->where('status', $request->string('status')->toString());
            }

            if ($request->filled('code')) {
                $query->where('code', $request->string('code')->toString());
            }

            if ($request->filled('domain')) {
                $query->where('domain', $request->string('domain')->toString());
            }

            $hubs = $query->get()
                ->map(fn (Hub $hub) => $this->toRelayHubPayload($hub))
                ->values();

            return [
                'hubs' => $hubs,
                'count' => $hubs->count(),
            ];
        });

        return $this->ok($cached['data'], null, 200, $cached['headers']);
    }

    public function show(Hub $hub)
    {
        $cached = $this->rememberHubsPayload('hubs:canonical:show', ['hub' => (int) $hub->id], function () use ($hub) {
            $hub->load([
                'token',
                'uplinks.uplinkHub:id,name,code,deployment,domain,status',
                'downstreamUplinks.hub:id,name,code,deployment,domain,status',
            ]);

            return [
                'hub' => $this->toRelayHubPayload($hub),
            ];
        });

        return $this->ok($cached['data'], null, 200, $cached['headers']);
    }

    public function store(Request $request)
    {
        $data = $this->validateHub($request);
        $hub = Hub::create($this->hubAttributes($data, true));
        if (array_key_exists('uplink_hub_ids', $data)) {
            $this->syncManualUplinks($hub, $data['uplink_hub_ids'] ?? []);
        }
        $this->syncHierarchyUplinks($hub);

        $this->syncHubTokenState($hub);
        $this->bumpHubsCacheVersion();

        return $this->ok([
            'hub' => $this->toHubPayload($hub->load(['token', 'uplinks.uplinkHub:id,name,domain', 'downstreamUplinks.hub:id,name,domain'])),
        ], null, 201);
    }

    public function update(Request $request, Hub $hub)
    {
        $data = $this->validateHub($request, $hub);
        $hub->fill($this->hubAttributes($data, false));
        $hub->save();
        if (array_key_exists('uplink_hub_ids', $data)) {
            $this->syncManualUplinks($hub, $data['uplink_hub_ids'] ?? []);
        }
        $this->syncHierarchyUplinks($hub);

        $this->syncHubTokenState($hub);
        $this->bumpHubsCacheVersion();

        return $this->ok([
            'hub' => $this->toHubPayload($hub->load(['token', 'uplinks.uplinkHub:id,name,domain', 'downstreamUplinks.hub:id,name,domain'])),
        ]);
    }

    public function destroy(Hub $hub)
    {
        $hub->delete();
        $this->bumpHubsCacheVersion();

        return $this->ok();
    }

    private function validateHub(Request $request, ?Hub $hub = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('hubs', 'code')->ignore($hub?->id),
            ],
            'relay_hub_id' => [
                'nullable',
                'string',
                'max:191',
                Rule::unique('hubs', 'relay_hub_id')->ignore($hub?->id),
            ],
            'deployment' => ['required', Rule::in($this->hubLevels())],
            'country_code' => ['nullable', 'string', 'max:8'],
            'reg_code' => ['nullable', 'string', 'max:16'],
            'prov_code' => ['nullable', 'string', 'max:16'],
            'citymun_code' => ['nullable', 'string', 'max:16'],
            'brgy_code' => ['nullable', 'string', 'max:16'],
            'domain' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('hubs', 'domain')->ignore($hub?->id),
            ],
            'status' => ['required', Rule::in($this->statuses())],
            'last_seen_at' => ['nullable', 'date'],
            'last_response_ms' => ['nullable', 'integer', 'min:0'],
            'deployed_at' => ['nullable', 'date'],
            'uplink_hub_ids' => ['nullable', 'array'],
            'uplink_hub_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('hubs', 'id'),
                Rule::notIn([$hub?->id]),
            ],
        ]);
    }

    private function hubAttributes(array $data, bool $isCreate): array
    {
        $attributes = [
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'relay_hub_id' => $data['relay_hub_id'] ?? null,
            'deployment' => $data['deployment'],
            'domain' => $data['domain'] ?? null,
            'status' => $data['status'],
        ];

        foreach (['country_code', 'reg_code', 'prov_code', 'citymun_code', 'brgy_code', 'last_seen_at', 'last_response_ms', 'deployed_at'] as $key) {
            if ($isCreate || array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key] ?? null;
            }
        }

        return $attributes;
    }

    private function syncManualUplinks(Hub $hub, array $uplinkHubIds): void
    {
        $ids = collect($uplinkHubIds)
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter()
            ->values();

        $existing = $hub->uplinks()->where('uplink_type', 'manual')->pluck('id', 'uplink_hub_id');
        $seen = [];

        foreach ($ids as $index => $uplinkHubId) {
            $uplinkHub = Hub::query()->find($uplinkHubId);
            if (! $uplinkHub) {
                continue;
            }

            $hub->uplinks()->updateOrCreate(
                ['uplink_hub_id' => $uplinkHubId, 'uplink_type' => 'manual'],
                [
                    'uplink_domain' => $uplinkHub->domain,
                    'uplink_type' => 'manual',
                    'priority' => $index + 1,
                    'is_primary' => false,
                ]
            );
            $seen[] = $uplinkHubId;
        }

        $deleteIds = $existing->keys()->diff($seen)->values();
        if ($deleteIds->isNotEmpty()) {
            $hub->uplinks()->where('uplink_type', 'manual')->whereIn('uplink_hub_id', $deleteIds)->delete();
        }
    }

    private function syncHierarchyUplinks(Hub $hub): void
    {
        $hub->loadMissing('uplinks');

        $hub->uplinks()
            ->where('uplink_type', 'hierarchy')
            ->delete();

        $parent = $this->resolveHierarchyParent($hub);
        if ($parent) {
            $this->ensureHierarchyUplink($hub, $parent);
        }

        $children = $this->resolveHierarchyChildren($hub);
        $childIds = $children->pluck('id')->map(fn ($id) => (int) $id)->values();

        HubUplink::query()
            ->where('uplink_type', 'hierarchy')
            ->where('uplink_hub_id', $hub->id)
            ->when(
                $childIds->isNotEmpty(),
                fn ($query) => $query->whereNotIn('hub_id', $childIds->all())
            )
            ->delete();

        foreach ($children as $childHub) {
            $this->ensureHierarchyUplink($childHub, $hub);
        }
    }

    private function ensureHierarchyUplink(Hub $hub, Hub $uplinkHub): void
    {
        if ($hub->id === $uplinkHub->id) {
            return;
        }

        $existing = $hub->uplinks()->orderBy('priority')->get();
        $current = $existing->firstWhere('uplink_hub_id', $uplinkHub->id);
        $otherPrimary = $existing->firstWhere('is_primary', true);
        $priority = $current?->priority ?? (($existing->max('priority') ?? 0) + 1);
        $isPrimary = $current?->is_primary ?? ($otherPrimary ? false : true);

        $hub->uplinks()->updateOrCreate(
            [
                'uplink_hub_id' => $uplinkHub->id,
            ],
            [
                'uplink_domain' => $uplinkHub->domain,
                'uplink_type' => 'hierarchy',
                'priority' => $priority,
                'is_primary' => $isPrimary,
            ]
        );
    }

    private function resolveHierarchyParent(Hub $hub): ?Hub
    {
        return match ($hub->deployment) {
            'barangay' => Hub::query()
                ->where('deployment', 'city')
                ->where('citymun_code', $hub->citymun_code)
                ->orderBy('id')
                ->first(),
            'city' => Hub::query()
                ->where('deployment', 'province')
                ->where('prov_code', $hub->prov_code)
                ->orderBy('id')
                ->first(),
            'province' => Hub::query()
                ->where('deployment', 'region')
                ->where('reg_code', $hub->reg_code)
                ->orderBy('id')
                ->first(),
            'region' => Hub::query()
                ->where('deployment', 'national')
                ->where('country_code', $hub->country_code)
                ->orderBy('id')
                ->first(),
            default => null,
        };
    }

    private function resolveHierarchyChildren(Hub $hub)
    {
        return match ($hub->deployment) {
            'national' => Hub::query()
                ->where('deployment', 'region')
                ->where('country_code', $hub->country_code)
                ->get(),
            'region' => Hub::query()
                ->where('deployment', 'province')
                ->where('reg_code', $hub->reg_code)
                ->get(),
            'province' => Hub::query()
                ->where('deployment', 'city')
                ->where('prov_code', $hub->prov_code)
                ->get(),
            'city' => Hub::query()
                ->where('deployment', 'barangay')
                ->where('citymun_code', $hub->citymun_code)
                ->get(),
            default => collect(),
        };
    }

    private function toHubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'code' => $hub->code,
            'relay_hub_id' => $hub->relay_hub_id,
            'deployment' => $hub->deployment,
            'country_code' => $hub->country_code,
            'reg_code' => $hub->reg_code,
            'prov_code' => $hub->prov_code,
            'citymun_code' => $hub->citymun_code,
            'brgy_code' => $hub->brgy_code,
            'domain' => $hub->domain,
            'status' => $hub->status,
            'last_seen_at' => optional($hub->last_seen_at)->toIso8601String(),
            'last_response_ms' => $hub->last_response_ms,
            'heartbeat_status' => $hub->heartbeat_status,
            'heartbeat_checked_at' => optional($hub->heartbeat_checked_at)->toIso8601String(),
            'heartbeat_error' => $hub->heartbeat_error,
            'heartbeat_app_version' => $hub->heartbeat_app_version,
            'heartbeat_protocol_version' => $hub->heartbeat_protocol_version,
            'heartbeat_delivery_queued' => $hub->heartbeat_delivery_queued,
            'heartbeat_delivery_failed' => $hub->heartbeat_delivery_failed,
            'heartbeat_delivery_dead' => $hub->heartbeat_delivery_dead,
            'heartbeat_handlers_failed' => $hub->heartbeat_handlers_failed,
            'heartbeat_capabilities' => $hub->heartbeat_capabilities ?? [],
            'deployed_at' => optional($hub->deployed_at)->toDateString(),
            'uplink_hub_ids' => $hub->uplinks->pluck('uplink_hub_id')->filter()->values()->all(),
            'manual_uplink_hub_ids' => $hub->uplinks
                ->where('uplink_type', 'manual')
                ->pluck('uplink_hub_id')
                ->filter()
                ->values()
                ->all(),
            'uplink_count' => $hub->uplinks->count(),
            'source_count' => $hub->downstreamUplinks->count(),
            'token' => [
                'has_token' => (bool) $hub->token,
                'is_active' => $hub->status === 'active' && $hub->token && ! $hub->token->revoked_at,
                'last_used_at' => $hub->token?->last_used_at?->toIso8601String(),
                'revoked_at' => $hub->token?->revoked_at?->toIso8601String(),
                'issued_at' => $hub->token?->created_at?->toIso8601String(),
            ],
            'uplinks' => $hub->uplinks->map(function ($uplink) {
                return [
                    'id' => $uplink->id,
                    'uplink_hub_id' => $uplink->uplink_hub_id,
                    'uplink_domain' => $uplink->uplink_domain,
                    'uplink_type' => $uplink->uplink_type,
                    'priority' => $uplink->priority,
                    'is_primary' => $uplink->is_primary,
                    'uplink_name' => optional($uplink->uplinkHub)->name,
                ];
            })->values()->all(),
            'sources' => $hub->downstreamUplinks->map(function ($uplink) {
                return [
                    'id' => $uplink->id,
                    'hub_id' => $uplink->hub_id,
                    'uplink_type' => $uplink->uplink_type,
                    'hub_name' => optional($uplink->hub)->name,
                    'hub_domain' => optional($uplink->hub)->domain,
                ];
            })->values()->all(),
        ];
    }

    private function hubLevels(): array
    {
        return ['barangay', 'city', 'province', 'region', 'national', 'foundation', 'other'];
    }

    private function statuses(): array
    {
        return ['planned', 'provisioning', 'active', 'inactive', 'maintenance', 'retired'];
    }

    private function toRelayHubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'code' => $hub->code,
            'relay_hub_id' => $hub->relay_hub_id,
            'deployment' => $hub->deployment,
            'domain' => $hub->domain,
            'status' => $hub->status,
            'country_code' => $hub->country_code,
            'reg_code' => $hub->reg_code,
            'prov_code' => $hub->prov_code,
            'citymun_code' => $hub->citymun_code,
            'brgy_code' => $hub->brgy_code,
            'last_seen_at' => optional($hub->last_seen_at)->toIso8601String(),
            'last_response_ms' => $hub->last_response_ms,
            'deployed_at' => optional($hub->deployed_at)->toDateString(),
            'token' => [
                'has_token' => (bool) $hub->token,
                'is_active' => $hub->status === 'active' && $hub->token && ! $hub->token->revoked_at,
                'last_used_at' => $hub->token?->last_used_at?->toIso8601String(),
                'revoked_at' => $hub->token?->revoked_at?->toIso8601String(),
                'issued_at' => $hub->token?->created_at?->toIso8601String(),
            ],
            'uplinks' => $hub->uplinks->map(function ($uplink) {
                return [
                    'id' => $uplink->id,
                    'uplink_hub_id' => $uplink->uplink_hub_id,
                    'uplink_type' => $uplink->uplink_type,
                    'uplink_domain' => $uplink->uplink_domain,
                    'priority' => $uplink->priority,
                    'is_primary' => $uplink->is_primary,
                    'hub' => $uplink->uplinkHub ? [
                        'id' => $uplink->uplinkHub->id,
                        'name' => $uplink->uplinkHub->name,
                        'code' => $uplink->uplinkHub->code,
                        'deployment' => $uplink->uplinkHub->deployment,
                        'domain' => $uplink->uplinkHub->domain,
                        'status' => $uplink->uplinkHub->status,
                    ] : null,
                ];
            })->values()->all(),
            'sources' => $hub->downstreamUplinks->map(function ($uplink) {
                return [
                    'id' => $uplink->id,
                    'hub_id' => $uplink->hub_id,
                    'uplink_type' => $uplink->uplink_type,
                    'is_primary' => $uplink->is_primary,
                    'priority' => $uplink->priority,
                    'hub' => $uplink->hub ? [
                        'id' => $uplink->hub->id,
                        'name' => $uplink->hub->name,
                        'code' => $uplink->hub->code,
                        'deployment' => $uplink->hub->deployment,
                        'domain' => $uplink->hub->domain,
                        'status' => $uplink->hub->status,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    private function syncHubTokenState(Hub $hub): void
    {
        $hub->loadMissing('token');

        if (! $hub->token) {
            return;
        }

        $hub->token->forceFill([
            'revoked_at' => $hub->status === 'active' ? null : now(),
        ])->save();
    }

    private function rememberHubsPayload(string $prefix, array $params, callable $resolver): array
    {
        $version = $this->hubsCacheVersion();
        $key = sprintf(
            '%s:v:%d:%s',
            $prefix,
            $version,
            md5(json_encode($this->normalizeCacheParams($params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        );

        $cacheHit = true;
        $data = $this->cacheStore()->get($key);

        if ($data === null) {
            $cacheHit = false;
            $data = $resolver();
            $this->cacheStore()->forever($key, $data);
        }

        return [
            'data' => $data,
            'headers' => [
                'X-Cache' => $cacheHit ? 'HIT' : 'MISS',
                'X-Cache-Store' => 'file_data_api',
                'X-Cache-Key' => $key,
                'X-Cache-Version' => (string) $version,
            ],
        ];
    }

    private function normalizeCacheParams(array $params): array
    {
        ksort($params);

        return array_map(function ($value) {
            if (is_string($value)) {
                return trim($value);
            }

            return $value;
        }, $params);
    }

    private function hubsCacheVersion(): int
    {
        return (int) $this->cacheStore()->get('hubs:version', 1);
    }

    private function bumpHubsCacheVersion(): void
    {
        $this->cacheStore()->forever('hubs:version', $this->hubsCacheVersion() + 1);
    }

    private function cacheStore()
    {
        return Cache::store('file_data_api');
    }
}
