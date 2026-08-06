<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['incident_id']);
                $table->dropForeign(['call_session_id']);
            }

            $table->unsignedBigInteger('incident_id')->nullable()->change();
            $table->unsignedBigInteger('call_session_id')->nullable()->change();
        });

        Schema::table('media', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('incident_id')->references('id')->on('incidents')->restrictOnDelete();
                $table->foreign('call_session_id')->references('id')->on('call_sessions')->restrictOnDelete();
            }

            $table->index(['type', 'created_at'], 'media_type_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex('media_type_created_at_index');

            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['incident_id']);
                $table->dropForeign(['call_session_id']);
            }

            $table->unsignedBigInteger('incident_id')->nullable(false)->change();
            $table->unsignedBigInteger('call_session_id')->nullable(false)->change();
        });

        Schema::table('media', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('incident_id')->references('id')->on('incidents')->restrictOnDelete();
                $table->foreign('call_session_id')->references('id')->on('call_sessions')->restrictOnDelete();
            }
        });
    }
};
