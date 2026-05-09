<?php

namespace App\Domain\Teams\Models;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamAssignmentNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_assignment_id',
        'created_by_operator_id',
        'note',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TeamAssignment::class, 'team_assignment_id');
    }

    public function createdByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_operator_id');
    }
}
