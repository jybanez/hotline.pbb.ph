<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missing = $this->missingColumns();

        if ($missing === []) {
            return;
        }

        Schema::table('message_attachments', function (Blueprint $table) use ($missing): void {
            if (in_array('original_mime_type', $missing, true)) {
                $table->string('original_mime_type')->nullable()->after('original_filename');
            }

            if (in_array('stored_mime_type', $missing, true)) {
                $table->string('stored_mime_type')->nullable()->after('original_mime_type');
            }

            if (in_array('stored_filename', $missing, true)) {
                $table->string('stored_filename')->nullable()->after('stored_path');
            }

            if (in_array('stored_size_bytes', $missing, true)) {
                $table->unsignedBigInteger('stored_size_bytes')->nullable()->after('file_size');
            }

            if (in_array('image_width', $missing, true)) {
                $table->unsignedInteger('image_width')->nullable()->after('stored_size_bytes');
            }

            if (in_array('image_height', $missing, true)) {
                $table->unsignedInteger('image_height')->nullable()->after('image_width');
            }

            if (in_array('sha256', $missing, true)) {
                $table->string('sha256', 64)->nullable()->after('image_height');
            }

            if (in_array('normalized_at', $missing, true)) {
                $table->timestamp('normalized_at')->nullable()->after('sha256');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            $this->columns(),
            fn (string $column): bool => Schema::hasColumn('message_attachments', $column)
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('message_attachments', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    /**
     * @return list<string>
     */
    private function missingColumns(): array
    {
        return array_values(array_filter(
            $this->columns(),
            fn (string $column): bool => ! Schema::hasColumn('message_attachments', $column)
        ));
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'original_mime_type',
            'stored_mime_type',
            'stored_filename',
            'stored_size_bytes',
            'image_width',
            'image_height',
            'sha256',
            'normalized_at',
        ];
    }
};
