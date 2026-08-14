<?php

namespace App\Domain\Fallback\Models;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FallbackIncidentDrop extends Model
{
    use HasFactory;

    protected $fillable = [
        'citizen_id',
        'claimed_by_operator_id',
        'converted_incident_id',
        'status',
        'reason',
        'citizen_latitude',
        'citizen_longitude',
        'citizen_location_accuracy',
        'quick_category',
        'short_description',
        'contact_snapshot',
        'closure_disposition',
        'closure_note',
        'claimed_at',
        'converted_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'citizen_latitude' => 'float',
            'citizen_longitude' => 'float',
            'citizen_location_accuracy' => 'float',
            'contact_snapshot' => 'array',
            'claimed_at' => 'datetime',
            'converted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function claimedByOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_operator_id');
    }

    public function convertedIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'converted_incident_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FallbackIncidentDropAttachment::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(FallbackIncidentDropHistory::class);
    }
}
