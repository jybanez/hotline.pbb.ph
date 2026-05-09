<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'sender_name',
                'sender_avatar',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('incident_messages', function (Blueprint $table): void {
            $table->string('sender_name')->nullable()->after('sender_role');
            $table->string('sender_avatar')->nullable()->after('sender_name');
        });
    }
};
