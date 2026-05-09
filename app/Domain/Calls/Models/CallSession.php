<?php

namespace App\Domain\Calls\Models;

use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'caller_id',
        'status',
        'outcome',
        'started_at',
        'answered_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'outcome' => CallOutcome::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Incidents\Models\Incident::class);
    }
}
