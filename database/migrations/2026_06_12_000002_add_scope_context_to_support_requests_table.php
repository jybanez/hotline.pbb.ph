<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->json('selected_incident_ids_json')->nullable()->after('incident_refs_json');
            $table->json('support_context_json')->nullable()->after('selected_incident_ids_json');
        });
    }

    public function down(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'selected_incident_ids_json',
                'support_context_json',
            ]);
        });
    }
};
