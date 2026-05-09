<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'caller')
            ->update(['role' => 'citizen']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'citizen')
            ->update(['role' => 'caller']);
    }
};
