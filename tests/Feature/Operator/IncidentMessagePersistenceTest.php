<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidentMessagePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_persist_caller_and_operator_messages_for_owned_incident(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/incidents/{$incidentId}/messages", [
                'body' => 'Caller says they need help.',
                'sender' => [
                    'id' => $caller->id,
                    'role' => 'caller',
                    'name' => $caller->name,
                    'avatar' => $caller->avatar,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('item.sender_id', $caller->id)
            ->assertJsonPath('item.sender_role', 'caller')
            ->assertJsonPath('item.body', 'Caller says they need help.');

        $this->actingAs($operator)
            ->postJson("/api/incidents/{$incidentId}/messages", [
                'body' => 'Operator acknowledged the caller.',
                'sender' => [
                    'id' => $operator->id,
                    'role' => 'operator',
                    'name' => $operator->name,
                    'avatar' => $operator->avatar,
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('item.sender_id', $operator->id)
            ->assertJsonPath('item.sender_role', 'operator')
            ->assertJsonPath('item.body', 'Operator acknowledged the caller.');

        $this->assertDatabaseHas('incident_messages', [
            'incident_id' => $incidentId,
            'sender_id' => $caller->id,
            'sender_role' => 'caller',
            'body' => 'Caller says they need help.',
        ]);

        $this->assertDatabaseHas('incident_messages', [
            'incident_id' => $incidentId,
            'sender_id' => $operator->id,
            'sender_role' => 'operator',
            'body' => 'Operator acknowledged the caller.',
        ]);
    }

    public function test_operator_cannot_persist_caller_message_for_different_caller(): void
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $otherCaller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($operator)
            ->postJson("/api/incidents/{$incidentId}/messages", [
                'body' => 'Invalid caller sender.',
                'sender' => [
                    'id' => $otherCaller->id,
                    'role' => 'caller',
                    'name' => $otherCaller->name,
                    'avatar' => $otherCaller->avatar,
                ],
            ])
            ->assertStatus(422);
    }

    public function test_operator_can_persist_message_attachments_for_owned_incident_message(): void
    {
        Storage::fake('public');

        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
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
            'sender_id' => $caller->id,
            'sender_role' => 'caller',
            'body' => 'Attachment incoming.',
            'type' => 'message',
            'created_at' => now(),
        ]);

        $file = UploadedFile::fake()->image('scene.jpg', 640, 480);

        $this->actingAs($operator)
            ->post("/api/incidents/{$incidentId}/messages/{$messageId}/attachments", [
                'attachment' => $file,
                'type' => 'image',
            ])
            ->assertCreated()
            ->assertJsonPath('item.message_id', $messageId)
            ->assertJsonPath('item.type', 'image')
            ->assertJsonPath('item.mime_type', 'image/webp')
            ->assertJsonPath('item.original_mime_type', 'image/jpeg')
            ->assertJsonPath('item.stored_mime_type', 'image/webp')
            ->assertJsonPath('item.original_filename', 'scene.jpg');

        $attachment = DB::table('message_attachments')->where('message_id', $messageId)->first();

        $this->assertNotNull($attachment);
        $this->assertSame('image/webp', $attachment->mime_type);
        $this->assertSame('image/jpeg', $attachment->original_mime_type);
        $this->assertSame('image/webp', $attachment->stored_mime_type);
        $this->assertStringEndsWith('.webp', $attachment->stored_path);
        $this->assertStringEndsWith('.webp', $attachment->stored_filename);
        $this->assertSame((int) $attachment->file_size, (int) $attachment->stored_size_bytes);
        $this->assertSame(640, (int) $attachment->image_width);
        $this->assertSame(480, (int) $attachment->image_height);
        $this->assertNotNull($attachment->sha256);
        $this->assertNotNull($attachment->normalized_at);
        Storage::disk('public')->assertExists($attachment->stored_path);
        $this->assertSame($attachment->sha256, hash('sha256', Storage::disk('public')->get($attachment->stored_path)));
        $this->assertSame('image/webp', (string) getimagesize(Storage::disk('public')->path($attachment->stored_path))['mime']);
        $this->assertNotNull($attachment->thumbnail_path);
        Storage::disk('public')->assertExists($attachment->thumbnail_path);
    }

    public function test_non_photo_attachments_keep_existing_storage_behavior(): void
    {
        Storage::fake('public');

        [$incidentId, $messageId, $operator] = $this->createOwnedIncidentMessage();

        $file = UploadedFile::fake()->create('brief.txt', 4, 'text/plain');

        $this->actingAs($operator)
            ->post("/api/incidents/{$incidentId}/messages/{$messageId}/attachments", [
                'attachment' => $file,
                'type' => 'file',
            ])
            ->assertCreated()
            ->assertJsonPath('item.type', 'file')
            ->assertJsonPath('item.mime_type', 'text/plain')
            ->assertJsonPath('item.original_filename', 'brief.txt')
            ->assertJsonPath('item.stored_mime_type', null)
            ->assertJsonPath('item.normalized_at', null);

        $attachment = DB::table('message_attachments')->where('message_id', $messageId)->first();

        $this->assertNotNull($attachment);
        $this->assertSame('text/plain', $attachment->mime_type);
        $this->assertNull($attachment->stored_mime_type);
        $this->assertNull($attachment->sha256);
        $this->assertStringEndsWith('.txt', $attachment->stored_path);
        Storage::disk('public')->assertExists($attachment->stored_path);
    }

    public function test_legacy_image_attachment_rows_are_reported_unavailable_until_backfilled(): void
    {
        [$incidentId, $messageId, $operator] = $this->createOwnedIncidentMessage();

        DB::table('message_attachments')->insert([
            'message_id' => $messageId,
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'legacy-scene.jpg',
            'stored_path' => 'incident-messages/legacy-scene.jpg',
            'file_size' => 123,
            'thumbnail_path' => null,
            'uploaded_by' => $operator->id,
            'created_at' => now(),
        ]);

        $this->actingAs($operator)
            ->getJson("/api/incidents/{$incidentId}/messages")
            ->assertOk()
            ->assertJsonPath('items.0.attachments.0.available', false)
            ->assertJsonPath('items.0.attachments.0.error', 'image_attachment_not_normalized')
            ->assertJsonPath('items.0.attachments.0.mime_type', null)
            ->assertJsonPath('items.0.attachments.0.file_size', null)
            ->assertJsonPath('items.0.attachments.0.stored_mime_type', null)
            ->assertJsonPath('items.0.attachments.0.normalized_at', null);
    }

    public function test_legacy_non_image_attachment_rows_remain_readable(): void
    {
        [$incidentId, $messageId, $operator] = $this->createOwnedIncidentMessage();

        DB::table('message_attachments')->insert([
            'message_id' => $messageId,
            'type' => 'file',
            'mime_type' => 'application/pdf',
            'original_filename' => 'legacy-brief.pdf',
            'stored_path' => 'incident-messages/legacy-brief.pdf',
            'file_size' => 321,
            'thumbnail_path' => null,
            'uploaded_by' => $operator->id,
            'created_at' => now(),
        ]);

        $this->actingAs($operator)
            ->getJson("/api/incidents/{$incidentId}/messages")
            ->assertOk()
            ->assertJsonPath('items.0.attachments.0.available', true)
            ->assertJsonPath('items.0.attachments.0.mime_type', 'application/pdf')
            ->assertJsonPath('items.0.attachments.0.file_size', 321)
            ->assertJsonPath('items.0.attachments.0.stored_mime_type', null)
            ->assertJsonPath('items.0.attachments.0.normalized_at', null);
    }

    /**
     * @return array{0:int,1:int,2:\App\Models\User}
     */
    private function createOwnedIncidentMessage(): array
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
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
            'sender_id' => $caller->id,
            'sender_role' => 'caller',
            'body' => 'Attachment incoming.',
            'type' => 'message',
            'created_at' => now(),
        ]);

        return [$incidentId, $messageId, $operator];
    }
}
