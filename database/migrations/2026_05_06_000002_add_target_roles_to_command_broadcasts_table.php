<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_broadcasts', function (Blueprint $table): void {
            $table->json('target_roles_json')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('command_broadcasts', function (Blueprint $table): void {
            $table->dropColumn('target_roles_json');
        });
    }
};
