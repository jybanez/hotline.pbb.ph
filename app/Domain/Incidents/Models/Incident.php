<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Media\Models\Media;
use App\Domain\Messages\Models\IncidentMessage;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Teams\Models\TeamAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'caller_id',
        'actual_caller_name',
        'actual_caller_relationship',
        'operator_id',
        'status',
        'alert_level',
        'latitude',
        'longitude',
        'caller_location_accuracy',
        'caller_altitude',
        'caller_altitude_accuracy',
        'caller_heading',
        'caller_heading_source',
        'caller_location_captured_at',
        'location',
        'location_road',
        'location_suburb',
        'location_barangay',
        'location_citymunicipality',
        'location_country',
        'other_details',
        'called_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'alert_level' => AlertLevel::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'caller_location_accuracy' => 'decimal:2',
            'caller_altitude' => 'decimal:2',
            'caller_altitude_accuracy' => 'decimal:2',
            'caller_heading' => 'decimal:2',
            'caller_location_captured_at' => 'datetime',
            'called_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Users\Models\User::class, 'caller_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Users\Models\User::class, 'operator_id');
    }

    public function callSessions(): HasMany
    {
        return $this->hasMany(\App\Domain\Calls\Models\CallSession::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(IncidentTransfer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(IncidentMessage::class);
    }

    public function callerLocations(): HasMany
    {
        return $this->hasMany(IncidentCallerLocation::class)
            ->orderBy('captured_at')
            ->orderBy('id');
    }

    public function mediaItems(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function teamAssignments(): HasMany
    {
        return $this->hasMany(TeamAssignment::class);
    }

    public function incidentTypes(): BelongsToMany
    {
        return $this->belongsToMany(IncidentType::class, 'incident_incident_type')
            ->withPivot('id')
            ->withTimestamps();
    }

    public function incidentTypeDetails(): HasMany
    {
        return $this->hasMany(IncidentTypeDetail::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function incidentResourcesNeeded(): HasMany
    {
        return $this->hasMany(IncidentResourceNeeded::class)
            ->orderBy('id');
    }
}
