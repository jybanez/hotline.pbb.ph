<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_resources_needed', function (Blueprint $table) {
            $table->foreignId('incident_type_id')
                ->nullable()
                ->after('incident_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incident_resources_needed', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_type_id');
        });
    }
};
