<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Teams\Models\ResourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTypeDefaultResource extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_type_id',
        'resource_type_id',
        'quantity_required',
        'notes',
        'sort_order',
    ];

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class, 'incident_type_id');
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }
}
