<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCitizenIdColumn('incidents', nullable: true, nullOnDelete: false);
        $this->addCitizenIdColumn('call_attempts', nullable: true, nullOnDelete: false);
        $this->addCitizenIdColumn('call_sessions', nullable: true, nullOnDelete: false);
        $this->addCitizenIdColumn('incident_caller_locations', nullable: true, nullOnDelete: true);

        $this->backfillCitizenId('incidents');
        $this->backfillCitizenId('call_attempts');
        $this->backfillCitizenId('call_sessions');
        $this->backfillCitizenId('incident_caller_locations');
    }

    public function down(): void
    {
        $this->dropCitizenIdColumn('incident_caller_locations');
        $this->dropCitizenIdColumn('call_sessions');
        $this->dropCitizenIdColumn('call_attempts');
        $this->dropCitizenIdColumn('incidents');
    }

    private function addCitizenIdColumn(string $table, bool $nullable, bool $nullOnDelete): void
    {
        if (Schema::hasColumn($table, 'citizen_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $schema) use ($nullable, $nullOnDelete): void {
            $column = $schema->foreignId('citizen_id')->after('caller_id');

            if ($nullable) {
                $column->nullable();
            }

            if ($nullOnDelete) {
                $column->constrained('users')->nullOnDelete();

                return;
            }

            $column->constrained('users')->restrictOnDelete();
        });
    }

    private function backfillCitizenId(string $table): void
    {
        DB::table($table)
            ->whereNull('citizen_id')
            ->whereNotNull('caller_id')
            ->update([
                'citizen_id' => DB::raw('caller_id'),
            ]);
    }

    private function dropCitizenIdColumn(string $table): void
    {
        if (! Schema::hasColumn($table, 'citizen_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $schema): void {
            $schema->dropForeign(['citizen_id']);
            $schema->dropColumn('citizen_id');
        });
    }
};
