<?php

namespace App\Domain\Incidents\Models;

use App\Domain\Media\Models\Media;
use App\Domain\Messages\Models\IncidentMessage;
use App\Domain\Shared\Concerns\SynchronizesCitizenIdentity;
use App\Domain\Shared\Concerns\SynchronizesCitizenIncidentDetails;
use App\Domain\Shared\Enums\AlertLevel;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Teams\Models\TeamAssignment;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use HasFactory;
    use SynchronizesCitizenIdentity;
    use SynchronizesCitizenIncidentDetails;

    protected $fillable = [
        'citizen_id',
        'actual_citizen_name',
        'actual_citizen_relationship',
        'operator_id',
        'status',
        'alert_level',
        'latitude',
        'longitude',
        'citizen_location_accuracy',
        'citizen_altitude',
        'citizen_altitude_accuracy',
        'citizen_heading',
        'citizen_heading_source',
        'citizen_location_captured_at',
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
            'citizen_location_accuracy' => 'decimal:2',
            'citizen_altitude' => 'decimal:2',
            'citizen_altitude_accuracy' => 'decimal:2',
            'citizen_heading' => 'decimal:2',
            'citizen_location_captured_at' => 'datetime',
            'called_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function citizen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'citizen_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
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
        return $this->hasMany(IncidentCitizenLocation::class)
            ->orderBy('captured_at')
            ->orderBy('id');
    }

    public function citizenLocations(): HasMany
    {
        return $this->hasMany(IncidentCitizenLocation::class)
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

    public function getCitizenIdAttribute(): mixed
    {
        return $this->attributes['citizen_id'] ?? $this->caller_id;
    }

    public function getCallerIdAttribute(): mixed
    {
        return $this->attributes['caller_id'] ?? $this->attributes['citizen_id'] ?? null;
    }

    public function getActualCitizenNameAttribute(): mixed
    {
        return $this->attributes['actual_citizen_name'] ?? $this->actual_caller_name;
    }

    public function getActualCallerNameAttribute(): mixed
    {
        return $this->attributes['actual_caller_name'] ?? $this->attributes['actual_citizen_name'] ?? null;
    }

    public function getActualCitizenRelationshipAttribute(): mixed
    {
        return $this->attributes['actual_citizen_relationship'] ?? $this->actual_caller_relationship;
    }

    public function getActualCallerRelationshipAttribute(): mixed
    {
        return $this->attributes['actual_caller_relationship'] ?? $this->attributes['actual_citizen_relationship'] ?? null;
    }

    public function getCitizenLocationAccuracyAttribute(): mixed
    {
        return $this->attributes['citizen_location_accuracy'] ?? $this->caller_location_accuracy;
    }

    public function getCallerLocationAccuracyAttribute(): mixed
    {
        return $this->attributes['caller_location_accuracy'] ?? $this->attributes['citizen_location_accuracy'] ?? null;
    }

    public function getCitizenAltitudeAttribute(): mixed
    {
        return $this->attributes['citizen_altitude'] ?? $this->caller_altitude;
    }

    public function getCallerAltitudeAttribute(): mixed
    {
        return $this->attributes['caller_altitude'] ?? $this->attributes['citizen_altitude'] ?? null;
    }

    public function getCitizenAltitudeAccuracyAttribute(): mixed
    {
        return $this->attributes['citizen_altitude_accuracy'] ?? $this->caller_altitude_accuracy;
    }

    public function getCallerAltitudeAccuracyAttribute(): mixed
    {
        return $this->attributes['caller_altitude_accuracy'] ?? $this->attributes['citizen_altitude_accuracy'] ?? null;
    }

    public function getCitizenHeadingAttribute(): mixed
    {
        return $this->attributes['citizen_heading'] ?? $this->caller_heading;
    }

    public function getCallerHeadingAttribute(): mixed
    {
        return $this->attributes['caller_heading'] ?? $this->attributes['citizen_heading'] ?? null;
    }

    public function getCitizenHeadingSourceAttribute(): mixed
    {
        return $this->attributes['citizen_heading_source'] ?? $this->caller_heading_source;
    }

    public function getCallerHeadingSourceAttribute(): mixed
    {
        return $this->attributes['caller_heading_source'] ?? $this->attributes['citizen_heading_source'] ?? null;
    }

    public function getCitizenLocationCapturedAtAttribute(): mixed
    {
        $value = $this->attributes['citizen_location_captured_at'] ?? null;

        return $value === null ? $this->caller_location_captured_at : $this->asDateTime($value);
    }

    public function getCallerLocationCapturedAtAttribute(): mixed
    {
        $value = $this->attributes['caller_location_captured_at'] ?? $this->attributes['citizen_location_captured_at'] ?? null;

        return $value === null ? null : $this->asDateTime($value);
    }
}
