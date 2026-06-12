<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->json('justification_codes')->nullable()->after('quantity_unit');
            $table->json('justification_labels')->nullable()->after('justification_codes');
        });
    }

    public function down(): void
    {
        Schema::table('support_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'justification_codes',
                'justification_labels',
            ]);
        });
    }
};
