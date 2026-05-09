<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_type_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_type_id')->constrained()->restrictOnDelete();
            $table->string('field_key');
            $table->string('field_label');
            $table->string('input_type');
            $table->json('options_json')->nullable();
            $table->text('default_value')->nullable();
            $table->string('placeholder')->nullable();
            $table->string('unit')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('min', 10, 2)->nullable();
            $table->decimal('max', 10, 2)->nullable();
            $table->decimal('step', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['incident_type_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_type_fields');
    }
};
