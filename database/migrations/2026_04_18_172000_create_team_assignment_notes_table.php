<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_assignment_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_assignment_id')->constrained('team_assignments')->cascadeOnDelete();
            $table->foreignId('created_by_operator_id')->constrained('users')->cascadeOnDelete();
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_assignment_notes');
    }
};
