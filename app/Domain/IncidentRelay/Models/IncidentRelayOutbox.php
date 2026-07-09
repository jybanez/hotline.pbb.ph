<?php

namespace App\Domain\IncidentRelay\Models;

use App\Domain\Incidents\Models\Incident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentRelayOutbox extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PROCESSING = 'processing';

    protected $table = 'incident_relay_outbox';

    protected $fillable = [
        'incident_id',
        'message_type',
        'status',
        'pending_since',
        'last_changed_at',
        'attempt_count',
        'last_attempted_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'pending_since' => 'datetime',
            'last_changed_at' => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
