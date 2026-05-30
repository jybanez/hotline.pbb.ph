<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sitrep_relay_deliveries MODIFY relay_message_id VARCHAR(64) NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sitrep_relay_deliveries MODIFY relay_message_id BIGINT UNSIGNED NULL');
        }
    }
};
