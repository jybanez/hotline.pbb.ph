<?php

namespace App\Domain\Incidents\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'sort_order',
    ];

    public function incidentTypes(): HasMany
    {
        return $this->hasMany(IncidentType::class, 'incident_category_id');
    }
}
