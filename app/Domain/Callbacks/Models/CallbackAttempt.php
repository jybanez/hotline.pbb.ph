<?php

namespace App\Domain\Callbacks\Models;

use App\Domain\Calls\Models\CallAttempt;
use App\Domain\Calls\Models\CallSession;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallbackAttempt extends Model
{
    protected $fillable = [
        'callback_case_id',
        'operator_id',
        'attempt_number',
        'started_at',
        'ended_at',
        'channel',
        'result',
        'call_attempt_id',
        'call_session_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function callbackCase(): BelongsTo
    {
        return $this->belongsTo(CallbackCase::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function callAttempt(): BelongsTo
    {
        return $this->belongsTo(CallAttempt::class);
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
