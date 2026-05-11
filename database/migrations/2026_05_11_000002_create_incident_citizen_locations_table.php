<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_citizen_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('citizen_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('call_session_id')->nullable()->constrained('call_sessions')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->decimal('altitude', 10, 2)->nullable();
            $table->decimal('altitude_accuracy', 10, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->string('heading_source')->nullable();
            $table->string('source')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['incident_id', 'captured_at']);
            $table->index(['incident_id', 'received_at']);
            $table->index(['call_session_id', 'captured_at']);
        });

        DB::table('incident_citizen_locations')->insertUsing([
            'id',
            'incident_id',
            'citizen_id',
            'operator_id',
            'call_session_id',
            'latitude',
            'longitude',
            'accuracy',
            'altitude',
            'altitude_accuracy',
            'heading',
            'heading_source',
            'source',
            'captured_at',
            'received_at',
            'created_at',
            'updated_at',
        ], DB::table('incident_caller_locations')->select([
            'id',
            'incident_id',
            'citizen_id',
            'operator_id',
            'call_session_id',
            'latitude',
            'longitude',
            'accuracy',
            'altitude',
            'altitude_accuracy',
            'heading',
            'heading_source',
            'source',
            'captured_at',
            'received_at',
            'created_at',
            'updated_at',
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_citizen_locations');
    }
};
