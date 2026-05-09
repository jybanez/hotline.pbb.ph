<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_incident_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('incident_type_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['incident_id', 'incident_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_incident_type');
    }
};
