<?php

namespace App\Domain\Teams\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamAssignmentAllocatedResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_assignment_id',
        'resource_type_id',
        'quantity_allocated',
    ];

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }
}
