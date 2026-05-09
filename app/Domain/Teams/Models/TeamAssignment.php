<?php

namespace App\Domain\Teams\Models;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'team_id',
        'assigned_by_operator_id',
        'status',
        'contact_person',
        'cancelled_from_status',
        'cancel_reason_code',
        'cancel_reason_note',
        'cancelled_by_operator_id',
        'assigned_at',
        'accepted_at',
        'enroute_at',
        'arrived_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'enroute_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_operator_id');
    }

    public function cancelledByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_operator_id');
    }

    public function allocatedResources(): HasMany
    {
        return $this->hasMany(TeamAssignmentAllocatedResource::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(TeamAssignmentNote::class)->orderBy('created_at');
    }
}
