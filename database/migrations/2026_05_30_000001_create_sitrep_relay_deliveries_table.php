<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sitrep_relay_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sitrep_report_id')->constrained('sitrep_reports')->cascadeOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('relay_id')->nullable()->index();
            $table->string('relay_message_id', 64)->nullable();
            $table->unsignedInteger('deliveries_count')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->json('response_json')->nullable();
            $table->timestamps();

            $table->unique('sitrep_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sitrep_relay_deliveries');
    }
};
