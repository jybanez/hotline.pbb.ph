<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubHeartbeatCheck extends Model
{
    protected $fillable = [
        'hub_id',
        'checked_at',
        'request_url',
        'response_ms',
        'http_status',
        'outcome',
        'health_status',
        'app_version',
        'protocol_version',
        'delivery_queued',
        'delivery_failed',
        'delivery_dead',
        'handlers_failed',
        'error_message',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'response_ms' => 'integer',
            'http_status' => 'integer',
            'delivery_queued' => 'integer',
            'delivery_failed' => 'integer',
            'delivery_dead' => 'integer',
            'handlers_failed' => 'integer',
            'payload_json' => 'array',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }
}
