<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Calls\Models\CallSession;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentCallerLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'caller_id',
        'operator_id',
        'call_session_id',
        'latitude',
        'longitude',
        'accuracy',
        'altitude',
        'altitude_accuracy',
        'heading',
        'heading_source',
        'source',
        'captured_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy' => 'decimal:2',
            'altitude' => 'decimal:2',
            'altitude_accuracy' => 'decimal:2',
            'heading' => 'decimal:2',
            'captured_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
