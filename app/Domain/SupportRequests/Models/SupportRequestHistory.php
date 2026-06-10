<?php

namespace App\Domain\SupportRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRequestHistory extends Model
{
    protected $fillable = [
        'support_request_id',
        'event_type',
        'status',
        'relay_message_id',
        'update_id',
        'support_request_external_id',
        'source_system',
        'actor_name',
        'message',
        'payload_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class);
    }
}
