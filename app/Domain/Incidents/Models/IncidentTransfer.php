<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTransfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'from_operator_id',
        'to_operator_id',
        'reason',
        'status',
        'requested_at',
        'accepted_at',
        'rejected_at',
        'cancelled_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function fromOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_operator_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function toOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_operator_id');
    }
}
