<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_attempts', function (Blueprint $table) {
            $table->boolean('callback')->default(false);
            $table->timestamp('answered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_attempts', function (Blueprint $table) {
            $table->dropColumn(['callback', 'answered_at']);
        });
    }
};
