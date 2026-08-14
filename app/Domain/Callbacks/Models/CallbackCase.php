<?php

namespace App\Domain\Callbacks\Models;

use App\Domain\Calls\Models\CallSession;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallbackCase extends Model
{
    protected $fillable = [
        'incident_id',
        'citizen_id',
        'operator_id',
        'source_call_session_id',
        'reason',
        'priority',
        'status',
        'open_case_key',
        'due_at',
        'completed_at',
        'final_disposition',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function sourceCallSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class, 'source_call_session_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CallbackAttempt::class)->orderBy('attempt_number');
    }
}
