<?php

namespace App\Domain\Incidents\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTypeField extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_type_id',
        'field_key',
        'field_label',
        'input_type',
        'options_json',
        'config_json',
        'default_value',
        'placeholder',
        'unit',
        'is_required',
        'sort_order',
        'min',
        'max',
        'step',
    ];

    protected $casts = [
        'options_json' => 'array',
        'config_json' => 'array',
        'is_required' => 'boolean',
        'min' => 'decimal:2',
        'max' => 'decimal:2',
        'step' => 'decimal:2',
    ];

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class, 'incident_type_id');
    }
}
