<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_relay_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('message_type', 96)->default('hotline.incident.upserted');
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('pending_since')->nullable()->index();
            $table->timestamp('last_changed_at')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_relay_outbox');
    }
};
