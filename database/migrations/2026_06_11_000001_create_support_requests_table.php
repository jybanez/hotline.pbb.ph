<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('local_request_id', 64)->unique();
            $table->string('correlation_id', 64)->unique();
            $table->string('support_request_id', 64)->nullable()->index();
            $table->string('status', 32)->default('requested')->index();
            $table->string('relay_delivery_status', 32)->default('pending')->index();
            $table->unsignedInteger('relay_attempt_count')->default(0);
            $table->string('relay_id', 64)->nullable()->index();
            $table->string('relay_message_id', 64)->nullable()->index();
            $table->unsignedInteger('relay_deliveries_count')->nullable();
            $table->text('relay_last_error')->nullable();
            $table->timestamp('relay_last_attempted_at')->nullable();
            $table->timestamp('relay_submitted_at')->nullable();
            $table->json('relay_response_json')->nullable();
            $table->string('urgency', 32)->default('normal')->index();
            $table->string('requested_assistance');
            $table->string('requested_capability', 120)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('quantity_unit', 40)->nullable();
            $table->text('staging_notes')->nullable();
            $table->text('command_notes')->nullable();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name')->nullable();
            $table->string('requester_role', 40)->nullable();
            $table->string('source_system', 80)->default('hotline.command');
            $table->string('source_hub_id', 64)->nullable();
            $table->string('source_relay_hub_id', 64)->nullable();
            $table->string('source_hub_name')->nullable();
            $table->json('source_snapshot_json')->nullable();
            $table->foreignId('sitrep_report_id')->nullable()->constrained('sitrep_reports')->nullOnDelete();
            $table->unsignedInteger('sitrep_sequence_number')->nullable();
            $table->timestamp('sitrep_generated_at')->nullable();
            $table->string('sitrep_section', 80)->nullable();
            $table->string('sitrep_evidence_ref')->nullable();
            $table->json('gap_json')->nullable();
            $table->json('evidence_row_json')->nullable();
            $table->json('incident_refs_json')->nullable();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'relay_delivery_status']);
            $table->index(['sitrep_report_id', 'sitrep_section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
