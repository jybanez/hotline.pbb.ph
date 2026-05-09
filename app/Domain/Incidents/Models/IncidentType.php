<?php

namespace App\Domain\Incidents\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_category_id',
        'name',
        'description',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(IncidentCategory::class, 'incident_category_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(IncidentTypeField::class, 'incident_type_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function defaultResources(): HasMany
    {
        return $this->hasMany(IncidentTypeDefaultResource::class, 'incident_type_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
