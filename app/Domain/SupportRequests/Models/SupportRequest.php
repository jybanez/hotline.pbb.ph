<?php

namespace App\Domain\SupportRequests\Models;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_RELAY_ACCEPTED = 'relay_accepted';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_CLOSED = 'closed';

    public const RELAY_PENDING = 'pending';
    public const RELAY_ACCEPTED = 'relay_accepted';
    public const RELAY_FAILED = 'failed';

    protected $fillable = [
        'local_request_id',
        'correlation_id',
        'support_request_id',
        'status',
        'relay_delivery_status',
        'relay_attempt_count',
        'relay_id',
        'relay_message_id',
        'relay_deliveries_count',
        'relay_last_error',
        'relay_last_attempted_at',
        'relay_submitted_at',
        'relay_response_json',
        'urgency',
        'requested_assistance',
        'requested_capability',
        'quantity',
        'quantity_unit',
        'justification_codes',
        'justification_labels',
        'staging_notes',
        'command_notes',
        'requester_user_id',
        'requester_name',
        'requester_role',
        'source_system',
        'source_hub_id',
        'source_relay_hub_id',
        'source_hub_name',
        'source_snapshot_json',
        'sitrep_report_id',
        'sitrep_sequence_number',
        'sitrep_generated_at',
        'sitrep_section',
        'sitrep_evidence_ref',
        'gap_json',
        'evidence_row_json',
        'incident_refs_json',
        'selected_incident_ids_json',
        'support_context_json',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'relay_attempt_count' => 'integer',
            'relay_deliveries_count' => 'integer',
            'relay_last_attempted_at' => 'datetime',
            'relay_submitted_at' => 'datetime',
            'relay_response_json' => 'array',
            'quantity' => 'integer',
            'justification_codes' => 'array',
            'justification_labels' => 'array',
            'source_snapshot_json' => 'array',
            'sitrep_sequence_number' => 'integer',
            'sitrep_generated_at' => 'datetime',
            'gap_json' => 'array',
            'evidence_row_json' => 'array',
            'incident_refs_json' => 'array',
            'selected_incident_ids_json' => 'array',
            'support_context_json' => 'array',
            'requested_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function sitrepReport(): BelongsTo
    {
        return $this->belongsTo(SitrepReport::class, 'sitrep_report_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SupportRequestHistory::class);
    }
}
