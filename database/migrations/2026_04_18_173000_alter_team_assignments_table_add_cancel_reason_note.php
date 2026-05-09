<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_assignments', function (Blueprint $table): void {
            $table->text('cancel_reason_note')->nullable()->after('cancel_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('team_assignments', function (Blueprint $table): void {
            $table->dropColumn('cancel_reason_note');
        });
    }
};
