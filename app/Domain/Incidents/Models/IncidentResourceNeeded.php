<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Teams\Models\ResourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentResourceNeeded extends Model
{
    use HasFactory;

    protected $table = 'incident_resources_needed';

    protected $fillable = [
        'incident_id',
        'incident_type_id',
        'resource_type_id',
        'quantity_required',
        'notes',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function resourceType(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class);
    }
}
