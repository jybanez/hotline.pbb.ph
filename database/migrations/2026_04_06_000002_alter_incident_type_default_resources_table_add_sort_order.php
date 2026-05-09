<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('incident_type_default_resources')) {
            return;
        }

        Schema::table('incident_type_default_resources', function (Blueprint $table) {
            if (!Schema::hasColumn('incident_type_default_resources', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('notes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('incident_type_default_resources')) {
            return;
        }

        Schema::table('incident_type_default_resources', function (Blueprint $table) {
            if (Schema::hasColumn('incident_type_default_resources', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
