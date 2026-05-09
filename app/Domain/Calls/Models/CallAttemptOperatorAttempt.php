<?php

namespace App\Domain\Calls\Models;

use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallAttemptOperatorAttempt extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'call_attempt_id',
        'operator_id',
        'status',
        'outcome',
        'started_at',
        'answered_at',
        'ended_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'outcome' => CallOutcome::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function callAttempt(): BelongsTo
    {
        return $this->belongsTo(CallAttempt::class);
    }
}
