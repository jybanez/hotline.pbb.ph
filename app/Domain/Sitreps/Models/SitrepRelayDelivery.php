<?php

namespace App\Domain\Sitreps\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitrepRelayDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'sitrep_report_id',
        'status',
        'attempt_count',
        'relay_id',
        'relay_message_id',
        'deliveries_count',
        'last_error',
        'last_attempted_at',
        'submitted_at',
        'response_json',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'relay_message_id' => 'string',
            'deliveries_count' => 'integer',
            'last_attempted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'response_json' => 'array',
        ];
    }

    public function sitrepReport(): BelongsTo
    {
        return $this->belongsTo(SitrepReport::class, 'sitrep_report_id');
    }
}
