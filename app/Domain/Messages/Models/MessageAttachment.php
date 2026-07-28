<?php

namespace App\Domain\Messages\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'type',
        'mime_type',
        'original_filename',
        'original_mime_type',
        'stored_mime_type',
        'stored_path',
        'stored_filename',
        'file_size',
        'stored_size_bytes',
        'image_width',
        'image_height',
        'sha256',
        'normalized_at',
        'thumbnail_path',
        'uploaded_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'stored_size_bytes' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'normalized_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(IncidentMessage::class, 'message_id');
    }
}
