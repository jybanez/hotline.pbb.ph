<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE `call_sessions`
    MODIFY `started_at` TIMESTAMP(3) NOT NULL,
    MODIFY `answered_at` TIMESTAMP(3) NULL DEFAULT NULL,
    MODIFY `ended_at` TIMESTAMP(3) NULL DEFAULT NULL,
    MODIFY `created_at` TIMESTAMP(3) NULL DEFAULT NULL,
    MODIFY `updated_at` TIMESTAMP(3) NULL DEFAULT NULL
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE `call_sessions`
    MODIFY `started_at` TIMESTAMP NOT NULL,
    MODIFY `answered_at` TIMESTAMP NULL DEFAULT NULL,
    MODIFY `ended_at` TIMESTAMP NULL DEFAULT NULL,
    MODIFY `created_at` TIMESTAMP NULL DEFAULT NULL,
    MODIFY `updated_at` TIMESTAMP NULL DEFAULT NULL
SQL);
    }
};
