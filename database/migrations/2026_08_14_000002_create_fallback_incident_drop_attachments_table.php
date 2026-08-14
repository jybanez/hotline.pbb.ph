<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fallback_incident_drop_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fallback_incident_drop_id');
            $table->string('type', 32)->default('image');
            $table->string('original_filename')->nullable();
            $table->string('original_mime_type', 120)->nullable();
            $table->string('stored_mime_type', 120)->default('image/webp');
            $table->string('stored_path');
            $table->string('stored_filename');
            $table->unsignedBigInteger('original_size_bytes')->nullable();
            $table->unsignedBigInteger('stored_size_bytes');
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->string('sha256', 64);
            $table->timestamp('normalized_at')->nullable();
            $table->timestamps();

            $table->index(['fallback_incident_drop_id', 'type'], 'fid_attach_drop_type_idx');
            $table->index('sha256', 'fid_attach_sha_idx');

            $table->foreign('fallback_incident_drop_id', 'fid_attach_drop_fk')
                ->references('id')
                ->on('fallback_incident_drops')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fallback_incident_drop_attachments');
    }
};
