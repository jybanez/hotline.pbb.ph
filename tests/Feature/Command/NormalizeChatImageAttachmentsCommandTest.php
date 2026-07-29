<?php

namespace Tests\Feature\Command;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NormalizeChatImageAttachmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_jpeg_message_attachment_is_converted_to_authoritative_webp(): void
    {
        Storage::fake('public');

        $attachmentId = $this->seedLegacyImageAttachment('legacy-scene.jpg', UploadedFile::fake()->image('legacy-scene.jpg', 320, 240)->getContent());

        $this->artisan('app:normalize-chat-image-attachments')
            ->assertExitCode(0);

        $attachment = DB::table('message_attachments')->where('id', $attachmentId)->first();

        $this->assertNotNull($attachment);
        $this->assertSame('image/webp', $attachment->mime_type);
        $this->assertSame('image/jpeg', $attachment->original_mime_type);
        $this->assertSame('image/webp', $attachment->stored_mime_type);
        $this->assertSame('attachment-'.str_pad((string) $attachmentId, 6, '0', STR_PAD_LEFT).'.webp', $attachment->stored_filename);
        $this->assertSame((int) $attachment->file_size, (int) $attachment->stored_size_bytes);
        $this->assertSame(320, (int) $attachment->image_width);
        $this->assertSame(240, (int) $attachment->image_height);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $attachment->sha256);
        $this->assertNotNull($attachment->normalized_at);
        $this->assertStringEndsWith('.webp', $attachment->stored_path);
        $this->assertStringEndsWith('_thumb.jpg', $attachment->thumbnail_path);

        Storage::disk('public')->assertExists('incident-messages/legacy-scene.jpg');
        Storage::disk('public')->assertExists($attachment->stored_path);
        Storage::disk('public')->assertExists($attachment->thumbnail_path);

        $bytes = Storage::disk('public')->get($attachment->stored_path);
        $this->assertSame($attachment->sha256, hash('sha256', $bytes));
        $this->assertSame('image/webp', (string) getimagesize(Storage::disk('public')->path($attachment->stored_path))['mime']);
    }

    public function test_missing_source_file_fails_loudly(): void
    {
        Storage::fake('public');

        $attachmentId = $this->seedLegacyImageAttachment('missing-scene.jpg', null);

        $this->artisan('app:normalize-chat-image-attachments')
            ->expectsOutputToContain("Failed normalizing image attachment #{$attachmentId}")
            ->assertExitCode(1);

        $this->assertDatabaseHas('message_attachments', [
            'id' => $attachmentId,
            'mime_type' => 'image/jpeg',
            'stored_mime_type' => null,
            'normalized_at' => null,
        ]);
    }

    public function test_corrupt_source_file_fails_loudly(): void
    {
        Storage::fake('public');

        $attachmentId = $this->seedLegacyImageAttachment('corrupt-scene.jpg', 'not image bytes');

        $this->artisan('app:normalize-chat-image-attachments')
            ->expectsOutputToContain("Failed normalizing image attachment #{$attachmentId}")
            ->assertExitCode(1);

        $this->assertDatabaseHas('message_attachments', [
            'id' => $attachmentId,
            'mime_type' => 'image/jpeg',
            'stored_mime_type' => null,
            'normalized_at' => null,
        ]);
    }

    private function seedLegacyImageAttachment(string $filename, ?string $contents): int
    {
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messageId = DB::table('incident_messages')->insertGetId([
            'incident_id' => $incidentId,
            'sender_id' => $operator->id,
            'sender_role' => 'operator',
            'body' => 'Legacy photo.',
            'type' => 'message',
            'created_at' => now(),
        ]);

        $storedPath = "incident-messages/{$filename}";
        if ($contents !== null) {
            Storage::disk('public')->put($storedPath, $contents);
        }

        return DB::table('message_attachments')->insertGetId([
            'message_id' => $messageId,
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => $filename,
            'stored_path' => $storedPath,
            'file_size' => $contents !== null ? strlen($contents) : 123,
            'thumbnail_path' => null,
            'uploaded_by' => $operator->id,
            'created_at' => now(),
        ]);
    }
}
