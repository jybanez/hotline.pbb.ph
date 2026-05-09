<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Hub extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'relay_hub_id',
        'deployment',
        'country_code',
        'reg_code',
        'prov_code',
        'citymun_code',
        'brgy_code',
        'domain',
        'status',
        'last_seen_at',
        'last_response_ms',
        'heartbeat_status',
        'heartbeat_checked_at',
        'heartbeat_error',
        'heartbeat_app_version',
        'heartbeat_protocol_version',
        'heartbeat_delivery_queued',
        'heartbeat_delivery_failed',
        'heartbeat_delivery_dead',
        'heartbeat_handlers_failed',
        'heartbeat_capabilities',
        'deployed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'heartbeat_checked_at' => 'datetime',
            'deployed_at' => 'date',
            'last_response_ms' => 'integer',
            'heartbeat_delivery_queued' => 'integer',
            'heartbeat_delivery_failed' => 'integer',
            'heartbeat_delivery_dead' => 'integer',
            'heartbeat_handlers_failed' => 'integer',
            'heartbeat_capabilities' => 'array',
        ];
    }

    public function uplinks(): HasMany
    {
        return $this->hasMany(HubUplink::class);
    }

    public function downstreamUplinks(): HasMany
    {
        return $this->hasMany(HubUplink::class, 'uplink_hub_id');
    }

    public function token(): HasOne
    {
        return $this->hasOne(HubToken::class);
    }

    public function heartbeatChecks(): HasMany
    {
        return $this->hasMany(HubHeartbeatCheck::class);
    }
}
