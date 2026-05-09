<?php

namespace App\Domain\Sitreps\Models;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitrepReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'sequence_number',
        'title',
        'coverage_area',
        'period_started_at',
        'period_ended_at',
        'generated_at',
        'published_at',
        'status',
        'visibility',
        'alert_level',
        'prepared_by_user_id',
        'reviewed_by_user_id',
        'summary_json',
        'situation_json',
        'damage_json',
        'population_json',
        'actions_json',
        'needs_json',
        'gaps_json',
        'source_snapshot_json',
        'privacy_redactions_json',
        'data_quality_json',
    ];

    protected function casts(): array
    {
        return [
            'period_started_at' => 'datetime',
            'period_ended_at' => 'datetime',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'summary_json' => 'array',
            'situation_json' => 'array',
            'damage_json' => 'array',
            'population_json' => 'array',
            'actions_json' => 'array',
            'needs_json' => 'array',
            'gaps_json' => 'array',
            'source_snapshot_json' => 'array',
            'privacy_redactions_json' => 'array',
            'data_quality_json' => 'array',
        ];
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === 'published' && $this->visibility === 'public';
    }
}
