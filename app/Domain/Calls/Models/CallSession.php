<?php

namespace App\Domain\Calls\Models;

use App\Domain\Shared\Concerns\SynchronizesCitizenIdentity;
use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallSession extends Model
{
    use HasFactory;
    use SynchronizesCitizenIdentity;

    protected $fillable = [
        'incident_id',
        'citizen_id',
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

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function getCitizenIdAttribute(): mixed
    {
        return $this->attributes['citizen_id'] ?? $this->caller_id;
    }

    public function getCallerIdAttribute(): mixed
    {
        return $this->attributes['caller_id'] ?? $this->attributes['citizen_id'] ?? null;
    }
}
