<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            if (! Schema::hasColumn('incidents', 'actual_citizen_name')) {
                $table->string('actual_citizen_name')->nullable()->after('actual_caller_name');
            }

            if (! Schema::hasColumn('incidents', 'actual_citizen_relationship')) {
                $table->string('actual_citizen_relationship')->nullable()->after('actual_caller_relationship');
            }

            if (! Schema::hasColumn('incidents', 'citizen_location_accuracy')) {
                $table->decimal('citizen_location_accuracy', 10, 2)->nullable()->after('caller_location_accuracy');
            }

            if (! Schema::hasColumn('incidents', 'citizen_altitude')) {
                $table->decimal('citizen_altitude', 10, 2)->nullable()->after('caller_altitude');
            }

            if (! Schema::hasColumn('incidents', 'citizen_altitude_accuracy')) {
                $table->decimal('citizen_altitude_accuracy', 10, 2)->nullable()->after('caller_altitude_accuracy');
            }

            if (! Schema::hasColumn('incidents', 'citizen_heading')) {
                $table->decimal('citizen_heading', 6, 2)->nullable()->after('caller_heading');
            }

            if (! Schema::hasColumn('incidents', 'citizen_heading_source')) {
                $table->string('citizen_heading_source')->nullable()->after('caller_heading_source');
            }

            if (! Schema::hasColumn('incidents', 'citizen_location_captured_at')) {
                $table->timestamp('citizen_location_captured_at')->nullable()->after('caller_location_captured_at');
            }
        });

        $this->backfill('actual_citizen_name', 'actual_caller_name');
        $this->backfill('actual_citizen_relationship', 'actual_caller_relationship');
        $this->backfill('citizen_location_accuracy', 'caller_location_accuracy');
        $this->backfill('citizen_altitude', 'caller_altitude');
        $this->backfill('citizen_altitude_accuracy', 'caller_altitude_accuracy');
        $this->backfill('citizen_heading', 'caller_heading');
        $this->backfill('citizen_heading_source', 'caller_heading_source');
        $this->backfill('citizen_location_captured_at', 'caller_location_captured_at');
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn([
                'actual_citizen_name',
                'actual_citizen_relationship',
                'citizen_location_accuracy',
                'citizen_altitude',
                'citizen_altitude_accuracy',
                'citizen_heading',
                'citizen_heading_source',
                'citizen_location_captured_at',
            ]);
        });
    }

    private function backfill(string $citizenColumn, string $callerColumn): void
    {
        DB::table('incidents')
            ->whereNull($citizenColumn)
            ->whereNotNull($callerColumn)
            ->update([
                $citizenColumn => DB::raw($callerColumn),
            ]);
    }
};
