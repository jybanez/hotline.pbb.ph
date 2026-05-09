<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_broadcasts', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('message');
            $table->string('tone')->default('info');
            $table->string('audience')->default('global');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('realtime_status')->nullable();
            $table->json('realtime_meta_json')->nullable();
            $table->timestamps();

            $table->index(['audience', 'published_at']);
            $table->index(['tone', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_broadcasts');
    }
};
