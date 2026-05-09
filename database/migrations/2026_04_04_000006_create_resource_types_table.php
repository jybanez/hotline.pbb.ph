<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_type_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('resource_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('resource_type_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('unit_label')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_types');
        Schema::dropIfExists('resource_type_categories');
    }
};
