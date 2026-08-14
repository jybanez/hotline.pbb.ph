<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callback_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('callback_case_id')->constrained('callback_cases')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('channel', 32)->default('pbb_call');
            $table->string('result', 64)->nullable();
            $table->foreignId('call_attempt_id')->nullable()->constrained('call_attempts')->nullOnDelete();
            $table->foreignId('call_session_id')->nullable()->constrained('call_sessions')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['callback_case_id', 'attempt_number'], 'callback_attempts_case_number_unique');
            $table->index(['operator_id', 'result']);
            $table->index(['call_attempt_id']);
            $table->index(['call_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_attempts');
    }
};
