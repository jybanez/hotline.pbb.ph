<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_type_default_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('resource_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_required')->default(1);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['incident_type_id', 'resource_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_type_default_resources');
    }
};
