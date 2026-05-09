<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by_operator_id')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->string('contact_person')->nullable();
            $table->string('cancelled_from_status')->nullable();
            $table->string('cancel_reason_code')->nullable();
            $table->foreignId('cancelled_by_operator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('enroute_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_assignments');
    }
};
