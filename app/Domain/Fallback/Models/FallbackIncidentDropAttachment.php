<?php

namespace App\Domain\Fallback\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FallbackIncidentDropAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'fallback_incident_drop_id',
        'type',
        'original_filename',
        'original_mime_type',
        'stored_mime_type',
        'stored_path',
        'stored_filename',
        'original_size_bytes',
        'stored_size_bytes',
        'image_width',
        'image_height',
        'sha256',
        'normalized_at',
    ];

    protected $hidden = [
        'stored_path',
    ];

    protected function casts(): array
    {
        return [
            'original_size_bytes' => 'integer',
            'stored_size_bytes' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'normalized_at' => 'datetime',
        ];
    }

    public function drop(): BelongsTo
    {
        return $this->belongsTo(FallbackIncidentDrop::class, 'fallback_incident_drop_id');
    }
}
