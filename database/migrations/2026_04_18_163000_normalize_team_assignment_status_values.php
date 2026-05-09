<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $statusMap = [
            'Assigned' => 'assigned',
            'Requested' => 'requested',
            'Accepted' => 'accepted',
            'En-route' => 'en_route',
            'On-Scene' => 'on_scene',
            'Completed' => 'completed',
            'Cancelled' => 'cancelled',
        ];

        foreach ($statusMap as $legacy => $normalized) {
            DB::table('team_assignments')
                ->where('status', $legacy)
                ->update(['status' => $normalized]);

            DB::table('team_assignments')
                ->where('cancelled_from_status', $legacy)
                ->update(['cancelled_from_status' => $normalized]);
        }
    }

    public function down(): void
    {
        $statusMap = [
            'assigned' => 'Assigned',
            'requested' => 'Requested',
            'accepted' => 'Accepted',
            'en_route' => 'En-route',
            'on_scene' => 'On-Scene',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];

        foreach ($statusMap as $normalized => $legacy) {
            DB::table('team_assignments')
                ->where('status', $normalized)
                ->update(['status' => $legacy]);

            DB::table('team_assignments')
                ->where('cancelled_from_status', $normalized)
                ->update(['cancelled_from_status' => $legacy]);
        }
    }
};
