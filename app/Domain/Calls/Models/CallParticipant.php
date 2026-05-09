<?php

namespace App\Domain\Calls\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CallParticipant extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'call_session_id',
        'user_id',
        'participant_role',
        'joined_at',
        'left_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
