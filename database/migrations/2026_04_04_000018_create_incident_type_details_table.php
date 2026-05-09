<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_type_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('incident_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('field_id')->constrained('incident_type_fields')->restrictOnDelete();
            $table->string('field_label');
            $table->string('field_key');
            $table->text('field_value')->nullable();
            $table->string('input_type');
            $table->json('options_json')->nullable();
            $table->string('unit')->nullable();
            $table->string('placeholder')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_type_details');
    }
};
