<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('media')
            ->where('type', 'caller_video')
            ->update(['type' => 'citizen_video']);

        DB::table('media')
            ->where('peer_role', 'caller')
            ->update(['peer_role' => 'citizen']);

        DB::table('call_participants')
            ->where('participant_role', 'caller')
            ->update(['participant_role' => 'citizen']);

        DB::table('call_attempts')
            ->where('outcome', 'cancelled_by_caller')
            ->update(['outcome' => 'cancelled_by_citizen']);

        DB::table('call_sessions')
            ->where('outcome', 'ended_by_caller')
            ->update(['outcome' => 'ended_by_citizen']);
    }

    public function down(): void
    {
        DB::table('call_sessions')
            ->where('outcome', 'ended_by_citizen')
            ->update(['outcome' => 'ended_by_caller']);

        DB::table('call_attempts')
            ->where('outcome', 'cancelled_by_citizen')
            ->update(['outcome' => 'cancelled_by_caller']);

        DB::table('call_participants')
            ->where('participant_role', 'citizen')
            ->update(['participant_role' => 'caller']);

        DB::table('media')
            ->where('peer_role', 'citizen')
            ->update(['peer_role' => 'caller']);

        DB::table('media')
            ->where('type', 'citizen_video')
            ->update(['type' => 'caller_video']);
    }
};
