<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_assignment_allocated_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_allocated')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_assignment_allocated_resources');
    }
};
