<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_attempt_operator_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['call_attempt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_attempt_operator_attempts');
    }
};
