<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caller_id')->constrained('users')->restrictOnDelete();
            $table->string('actual_caller_name');
            $table->string('actual_caller_relationship')->nullable();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->index();
            $table->string('alert_level');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('location')->nullable();
            $table->string('location_road')->nullable();
            $table->string('location_suburb')->nullable();
            $table->string('location_barangay')->nullable();
            $table->string('location_citymunicipality')->nullable();
            $table->string('location_country')->nullable();
            $table->text('other_details')->nullable();
            $table->timestamp('called_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['operator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
