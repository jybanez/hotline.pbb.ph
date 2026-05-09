<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caller_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('answered_by_operator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->decimal('caller_latitude', 10, 7)->nullable();
            $table->decimal('caller_longitude', 10, 7)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['caller_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_attempts');
    }
};
