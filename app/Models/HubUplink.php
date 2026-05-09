<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubUplink extends Model
{
    use HasFactory;

    protected $fillable = [
        'hub_id',
        'uplink_hub_id',
        'uplink_domain',
        'uplink_type',
        'priority',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function uplinkHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'uplink_hub_id');
    }
}
