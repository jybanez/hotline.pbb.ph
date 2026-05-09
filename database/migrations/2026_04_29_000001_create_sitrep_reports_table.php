<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sitrep_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence_number');
            $table->string('title');
            $table->string('coverage_area')->nullable();
            $table->timestamp('period_started_at');
            $table->timestamp('period_ended_at');
            $table->timestamp('generated_at');
            $table->timestamp('published_at')->nullable();
            $table->string('status')->default('draft');
            $table->string('visibility')->default('private');
            $table->string('alert_level')->nullable();
            $table->foreignId('prepared_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('summary_json')->nullable();
            $table->json('situation_json')->nullable();
            $table->json('damage_json')->nullable();
            $table->json('population_json')->nullable();
            $table->json('actions_json')->nullable();
            $table->json('needs_json')->nullable();
            $table->json('gaps_json')->nullable();
            $table->json('source_snapshot_json')->nullable();
            $table->json('privacy_redactions_json')->nullable();
            $table->json('data_quality_json')->nullable();
            $table->timestamps();

            $table->unique('sequence_number');
            $table->index(['status', 'visibility']);
            $table->index(['period_started_at', 'period_ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sitrep_reports');
    }
};
