<?php

namespace App\Domain\IncidentRelay\Models;

use App\Domain\Incidents\Models\Incident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentRelayDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'incident_id',
        'message_type',
        'status',
        'stable_incident_key',
        'revision',
        'idempotency_key',
        'payload_hash',
        'payload_summary_json',
        'relay_id',
        'relay_message_id',
        'deliveries_count',
        'attempted_at',
        'sent_at',
        'failed_at',
        'last_error',
        'response_json',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary_json' => 'array',
            'response_json' => 'array',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
