<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_relay_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('message_type', 96)->default('hotline.incident.upserted');
            $table->string('status', 32)->default('pending')->index();
            $table->string('stable_incident_key', 255)->index();
            $table->string('revision', 96)->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->string('payload_hash', 64);
            $table->json('payload_summary_json')->nullable();
            $table->string('relay_id', 64)->nullable()->index();
            $table->string('relay_message_id', 64)->nullable()->index();
            $table->unsignedInteger('deliveries_count')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('response_json')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_relay_deliveries');
    }
};
