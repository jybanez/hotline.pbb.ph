<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('call_session_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->foreignId('peer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('peer_role')->nullable();
            $table->string('peer_label')->nullable();
            $table->string('path');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('available_at')->nullable();

            $table->index(['incident_id', 'created_at']);
            $table->index(['incident_id', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
