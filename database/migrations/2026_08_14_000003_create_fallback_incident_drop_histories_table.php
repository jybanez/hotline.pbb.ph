<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fallback_incident_drop_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fallback_incident_drop_id');
            $table->foreignId('actor_id')->nullable();
            $table->string('event', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['fallback_incident_drop_id', 'created_at'], 'fid_hist_drop_created_idx');
            $table->index(['event', 'created_at'], 'fid_hist_event_created_idx');

            $table->foreign('fallback_incident_drop_id', 'fid_hist_drop_fk')
                ->references('id')
                ->on('fallback_incident_drops')
                ->cascadeOnDelete();
            $table->foreign('actor_id', 'fid_hist_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fallback_incident_drop_histories');
    }
};
