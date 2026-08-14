<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fallback_incident_drops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('citizen_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('claimed_by_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->string('status', 32)->default('new')->index();
            $table->string('reason', 64)->default('all_operators_busy')->index();
            $table->decimal('citizen_latitude', 10, 7)->nullable();
            $table->decimal('citizen_longitude', 10, 7)->nullable();
            $table->decimal('citizen_location_accuracy', 10, 2)->nullable();
            $table->string('quick_category', 120)->nullable();
            $table->text('short_description')->nullable();
            $table->json('contact_snapshot')->nullable();
            $table->string('closure_disposition', 64)->nullable();
            $table->text('closure_note')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['citizen_id', 'status']);
            $table->index(['claimed_by_operator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fallback_incident_drops');
    }
};
