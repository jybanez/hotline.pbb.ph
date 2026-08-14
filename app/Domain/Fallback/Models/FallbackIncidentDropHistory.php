<?php

namespace App\Domain\Fallback\Models;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FallbackIncidentDropHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'fallback_incident_drop_id',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'note',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function drop(): BelongsTo
    {
        return $this->belongsTo(FallbackIncidentDrop::class, 'fallback_incident_drop_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
