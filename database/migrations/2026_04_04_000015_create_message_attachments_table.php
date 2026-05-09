<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('incident_messages')->cascadeOnDelete();
            $table->string('type');
            $table->string('mime_type');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedBigInteger('file_size');
            $table->string('thumbnail_path')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
