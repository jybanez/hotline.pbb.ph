<?php

namespace App\Domain\Command\Models;

use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommandBroadcast extends Model
{
    protected $table = 'command_broadcasts';

    protected $fillable = [
        'title',
        'message',
        'tone',
        'audience',
        'target_roles_json',
        'created_by_user_id',
        'published_at',
        'expires_at',
        'realtime_status',
        'realtime_meta_json',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'target_roles_json' => 'array',
            'realtime_meta_json' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
