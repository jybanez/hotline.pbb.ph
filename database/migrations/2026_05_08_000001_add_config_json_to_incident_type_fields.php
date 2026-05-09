<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_type_fields', function (Blueprint $table): void {
            $table->json('config_json')->nullable()->after('options_json');
        });

        Schema::table('incident_type_details', function (Blueprint $table): void {
            $table->json('config_json')->nullable()->after('options_json');
        });
    }

    public function down(): void
    {
        Schema::table('incident_type_details', function (Blueprint $table): void {
            $table->dropColumn('config_json');
        });

        Schema::table('incident_type_fields', function (Blueprint $table): void {
            $table->dropColumn('config_json');
        });
    }
};
