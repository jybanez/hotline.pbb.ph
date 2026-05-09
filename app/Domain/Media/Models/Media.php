<?php

namespace App\Domain\Media\Models;

use App\Domain\Calls\Models\CallSession;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'media';

    protected $fillable = [
        'incident_id',
        'call_session_id',
        'type',
        'peer_user_id',
        'peer_role',
        'peer_label',
        'path',
        'duration_seconds',
        'metadata_json',
        'created_at',
        'available_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
            'available_at' => 'datetime',
        ];
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
