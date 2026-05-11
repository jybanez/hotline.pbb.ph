<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropForeignIdIfExists('incidents', 'caller_id');
        $this->dropIndexIfExists('call_attempts', 'call_attempts_caller_id_created_at_index');
        $this->dropForeignIdIfExists('call_attempts', 'caller_id');
        $this->dropForeignIdIfExists('call_sessions', 'caller_id');

        $this->dropColumnsIfExist('incidents', [
            'actual_caller_name',
            'actual_caller_relationship',
            'caller_location_accuracy',
            'caller_altitude',
            'caller_altitude_accuracy',
            'caller_heading',
            'caller_heading_source',
            'caller_location_captured_at',
        ]);

        Schema::dropIfExists('incident_caller_locations');
    }

    public function down(): void
    {
        $this->restoreCallerIdentityColumn('incidents', 'citizen_id', 'operator_id');
        $this->restoreCallerIdentityColumn('call_attempts', 'citizen_id', 'incident_id');
        $this->restoreCallerIdentityColumn('call_sessions', 'citizen_id', 'status');

        Schema::table('incidents', function (Blueprint $table): void {
            if (! Schema::hasColumn('incidents', 'actual_caller_name')) {
                $table->string('actual_caller_name')->nullable()->after('actual_citizen_name');
            }

            if (! Schema::hasColumn('incidents', 'actual_caller_relationship')) {
                $table->string('actual_caller_relationship')->nullable()->after('actual_citizen_relationship');
            }

            if (! Schema::hasColumn('incidents', 'caller_location_accuracy')) {
                $table->decimal('caller_location_accuracy', 10, 2)->nullable()->after('longitude');
            }

            if (! Schema::hasColumn('incidents', 'caller_altitude')) {
                $table->decimal('caller_altitude', 10, 2)->nullable()->after('citizen_location_accuracy');
            }

            if (! Schema::hasColumn('incidents', 'caller_altitude_accuracy')) {
                $table->decimal('caller_altitude_accuracy', 10, 2)->nullable()->after('citizen_altitude');
            }

            if (! Schema::hasColumn('incidents', 'caller_heading')) {
                $table->decimal('caller_heading', 8, 2)->nullable()->after('citizen_altitude_accuracy');
            }

            if (! Schema::hasColumn('incidents', 'caller_heading_source')) {
                $table->string('caller_heading_source')->nullable()->after('citizen_heading');
            }

            if (! Schema::hasColumn('incidents', 'caller_location_captured_at')) {
                $table->timestamp('caller_location_captured_at')->nullable()->after('citizen_heading_source');
            }
        });

        DB::table('incidents')->update([
            'actual_caller_name' => DB::raw('actual_citizen_name'),
            'actual_caller_relationship' => DB::raw('actual_citizen_relationship'),
            'caller_location_accuracy' => DB::raw('citizen_location_accuracy'),
            'caller_altitude' => DB::raw('citizen_altitude'),
            'caller_altitude_accuracy' => DB::raw('citizen_altitude_accuracy'),
            'caller_heading' => DB::raw('citizen_heading'),
            'caller_heading_source' => DB::raw('citizen_heading_source'),
            'caller_location_captured_at' => DB::raw('citizen_location_captured_at'),
        ]);

        if (! Schema::hasTable('incident_caller_locations')) {
            Schema::create('incident_caller_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->foreignId('caller_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('citizen_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('call_session_id')->nullable()->constrained('call_sessions')->nullOnDelete();
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->decimal('accuracy', 10, 2)->nullable();
                $table->decimal('altitude', 10, 2)->nullable();
                $table->decimal('altitude_accuracy', 10, 2)->nullable();
                $table->decimal('heading', 8, 2)->nullable();
                $table->string('heading_source')->nullable();
                $table->string('source')->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
                $table->index(['incident_id', 'captured_at']);
                $table->index(['citizen_id', 'captured_at']);
            });
        }

        if (Schema::hasTable('incident_citizen_locations')) {
            DB::table('incident_citizen_locations')
                ->orderBy('id')
                ->chunk(500, function ($rows): void {
                    $payload = $rows->map(fn ($row): array => [
                        'id' => $row->id,
                        'incident_id' => $row->incident_id,
                        'caller_id' => $row->citizen_id,
                        'citizen_id' => $row->citizen_id,
                        'operator_id' => $row->operator_id,
                        'call_session_id' => $row->call_session_id,
                        'latitude' => $row->latitude,
                        'longitude' => $row->longitude,
                        'accuracy' => $row->accuracy,
                        'altitude' => $row->altitude,
                        'altitude_accuracy' => $row->altitude_accuracy,
                        'heading' => $row->heading,
                        'heading_source' => $row->heading_source,
                        'source' => $row->source,
                        'captured_at' => $row->captured_at,
                        'received_at' => $row->received_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ])->all();

                    DB::table('incident_caller_locations')->insertOrIgnore($payload);
                });
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumnsIfExist(string $table, array $columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($existing): void {
            $table->dropColumn($existing);
        });
    }

    private function dropForeignIdIfExists(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column): void {
            $table->dropForeign([$column]);
            $table->dropColumn($column);
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        Schema::table($table, function (Blueprint $table) use ($index): void {
            try {
                $table->dropIndex($index);
            } catch (Throwable) {
                //
            }
        });
    }

    private function restoreCallerIdentityColumn(string $table, string $after, string $indexAfter): void
    {
        if (! Schema::hasColumn($table, 'caller_id')) {
            Schema::table($table, function (Blueprint $table) use ($after): void {
                $table->foreignId('caller_id')->nullable()->after($after)->constrained('users')->nullOnDelete();
            });
        }

        DB::table($table)->whereNull('caller_id')->update([
            'caller_id' => DB::raw('citizen_id'),
        ]);

        if ($table === 'call_attempts') {
            Schema::table($table, function (Blueprint $table) use ($indexAfter): void {
                $table->index(['caller_id', 'created_at'], "idx_{$indexAfter}_caller_created_at");
            });
        }
    }
};
