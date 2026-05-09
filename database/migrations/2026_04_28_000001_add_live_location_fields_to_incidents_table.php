<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->decimal('caller_location_accuracy', 10, 2)->nullable()->after('longitude');
            $table->decimal('caller_altitude', 10, 2)->nullable()->after('caller_location_accuracy');
            $table->decimal('caller_altitude_accuracy', 10, 2)->nullable()->after('caller_altitude');
            $table->decimal('caller_heading', 6, 2)->nullable()->after('caller_altitude_accuracy');
            $table->string('caller_heading_source')->nullable()->after('caller_heading');
            $table->timestamp('caller_location_captured_at')->nullable()->after('caller_heading_source');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'caller_location_accuracy',
                'caller_altitude',
                'caller_altitude_accuracy',
                'caller_heading',
                'caller_heading_source',
                'caller_location_captured_at',
            ]);
        });
    }
};
