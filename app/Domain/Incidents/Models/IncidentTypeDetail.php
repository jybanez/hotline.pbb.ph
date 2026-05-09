<?php

namespace App\Domain\Incidents\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTypeDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_id',
        'incident_type_id',
        'field_id',
        'field_label',
        'field_key',
        'field_value',
        'input_type',
        'options_json',
        'config_json',
        'unit',
        'placeholder',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options_json' => 'array',
        'config_json' => 'array',
        'is_required' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(IncidentTypeField::class, 'field_id');
    }
}
