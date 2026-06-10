<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_request_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_request_id')->constrained('support_requests')->cascadeOnDelete();
            $table->string('event_type', 80)->index();
            $table->string('status', 32)->nullable()->index();
            $table->string('relay_message_id', 64)->nullable()->index();
            $table->string('update_id', 64)->nullable()->index();
            $table->string('support_request_external_id', 64)->nullable()->index();
            $table->string('source_system', 80)->nullable();
            $table->string('actor_name')->nullable();
            $table->text('message')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['support_request_id', 'relay_message_id'], 'support_request_histories_request_relay_unique');
            $table->unique(['support_request_id', 'update_id'], 'support_request_histories_request_update_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_request_histories');
    }
};
