<?php

namespace App\Domain\Calls\Models;

use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'caller_id',
        'incident_id',
        'answered_by_operator_id',
        'status',
        'outcome',
        'caller_latitude',
        'caller_longitude',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'outcome' => CallOutcome::class,
            'caller_latitude' => 'decimal:7',
            'caller_longitude' => 'decimal:7',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function operatorAttempts(): HasMany
    {
        return $this->hasMany(CallAttemptOperatorAttempt::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function getCitizenIdAttribute(): mixed
    {
        return $this->caller_id;
    }
}
