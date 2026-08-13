<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callback_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('citizen_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_call_session_id')->nullable()->constrained('call_sessions')->nullOnDelete();
            $table->string('reason', 64);
            $table->string('priority', 32)->default('normal');
            $table->string('status', 32)->default('pending');
            $table->string('open_case_key', 32)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('final_disposition')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'reason', 'open_case_key'], 'callback_cases_open_reason_unique');
            $table->index(['operator_id', 'status', 'due_at']);
            $table->index(['incident_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_cases');
    }
};
