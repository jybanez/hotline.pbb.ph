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
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'actual_caller_relationship' => 'Self',
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
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'actual_caller_relationship' => 'Self',
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
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'actual_caller_relationship' => 'Self',
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
            ->assertJsonPath('item.original_filename', 'scene.jpg');

        $attachment = DB::table('message_attachments')->where('message_id', $messageId)->first();

        $this->assertNotNull($attachment);
        Storage::disk('public')->assertExists($attachment->stored_path);
        $this->assertNotNull($attachment->thumbnail_path);
        Storage::disk('public')->assertExists($attachment->thumbnail_path);
    }
}
