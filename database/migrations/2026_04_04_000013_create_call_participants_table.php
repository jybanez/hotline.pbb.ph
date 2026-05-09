<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('participant_role');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['call_session_id', 'user_id', 'joined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_participants');
    }
};
