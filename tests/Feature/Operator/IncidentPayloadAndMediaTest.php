<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IncidentPayloadAndMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_workbench_payload_includes_transfers_messages_media_and_team_assignments(): void
    {
        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $transferId = DB::table('incident_transfers')->insertGetId([
            'incident_id' => $incidentId,
            'from_operator_id' => $operator->id,
            'to_operator_id' => $otherOperator->id,
            'reason' => 'Handing over.',
            'status' => 'rejected',
            'requested_at' => now()->subMinutes(5),
            'rejected_at' => now()->subMinutes(4),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(4),
        ]);

        $messageId = DB::table('incident_messages')->insertGetId([
            'incident_id' => $incidentId,
            'sender_id' => $operator->id,
            'sender_role' => 'operator',
            'body' => 'Responders notified.',
            'type' => 'message',
            'created_at' => now()->subMinutes(3),
        ]);

        DB::table('message_attachments')->insert([
            'message_id' => $messageId,
            'type' => 'photo',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'scene.jpg',
            'stored_path' => 'incident-media/scene.jpg',
            'file_size' => 1024,
            'thumbnail_path' => 'incident-media/thumb-scene.jpg',
            'uploaded_by' => $operator->id,
            'created_at' => now()->subMinutes(2),
        ]);

        $teamCategoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Response',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => $teamCategoryId,
            'name' => 'Medical Team',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Equipment',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceTypeCategoryId,
            'name' => 'Stretcher',
            'unit_label' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = DB::table('team_assignments')->insertGetId([
            'incident_id' => $incidentId,
            'team_id' => $teamId,
            'assigned_by_operator_id' => $operator->id,
            'status' => 'Assigned',
            'contact_person' => 'Chief Ramos',
            'assigned_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        DB::table('team_assignment_allocated_resources')->insert([
            'team_assignment_id' => $assignmentId,
            'resource_type_id' => $resourceTypeId,
            'quantity_allocated' => 1,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        DB::table('media')->insert([
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'audio_peer',
                'peer_user_id' => $operator->id,
                'peer_role' => 'operator',
                'peer_label' => $operator->name,
                'path' => 'media/operator-audio.mp3',
                'duration_seconds' => 21,
                'metadata_json' => json_encode(['codec' => 'mp3']),
                'created_at' => now()->subMinute(),
                'available_at' => now()->subMinute(),
            ],
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'caller_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'caller',
                'peer_label' => $caller->name,
                'path' => 'media/caller-video.mp4',
                'duration_seconds' => 14,
                'metadata_json' => json_encode(['codec' => 'h264']),
                'created_at' => now(),
                'available_at' => now(),
            ],
        ]);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonPath('transfer_history.0.id', $transferId)
            ->assertJsonPath('transfer_history.0.to_operator.id', $otherOperator->id)
            ->assertJsonPath('messages.0.id', $messageId)
            ->assertJsonPath('messages.0.attachments.0.original_filename', 'scene.jpg')
            ->assertJsonPath('team_assignments.0.id', $assignmentId)
            ->assertJsonPath('team_assignments.0.allocated_resources.0.resource_type.name', 'Stretcher')
            ->assertJsonPath('media.0.type', 'audio_peer')
            ->assertJsonPath('media.1.type', 'citizen_video')
            ->assertJsonPath('media.1.peer_role', 'citizen');
    }

    public function test_caller_incident_payload_and_media_endpoint_only_expose_citizen_video_records(): void
    {
        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        DB::table('media')->insert([
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'audio_peer',
                'peer_user_id' => $operator->id,
                'peer_role' => 'operator',
                'peer_label' => $operator->name,
                'path' => 'media/operator-audio.mp3',
                'duration_seconds' => 21,
                'metadata_json' => json_encode(['codec' => 'mp3']),
                'created_at' => now()->subMinute(),
                'available_at' => now()->subMinute(),
            ],
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'caller_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'caller',
                'peer_label' => $caller->name,
                'path' => 'media/caller-video.mp4',
                'duration_seconds' => 14,
                'metadata_json' => json_encode(['codec' => 'h264']),
                'created_at' => now(),
                'available_at' => now(),
            ],
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'citizen_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'citizen',
                'peer_label' => $caller->name,
                'path' => 'media/citizen-video.mp4',
                'duration_seconds' => 11,
                'metadata_json' => json_encode(['codec' => 'h264']),
                'created_at' => now()->addSecond(),
                'available_at' => now(),
            ],
        ]);

        $this->actingAs($caller)
            ->getJson("/api/citizen/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonCount(2, 'media')
            ->assertJsonPath('media.0.type', 'citizen_video')
            ->assertJsonPath('media.1.type', 'citizen_video');

        $this->actingAs($caller)
            ->getJson("/api/incidents/{$incidentId}/media")
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.type', 'caller_video')
            ->assertJsonPath('items.1.type', 'citizen_video');

        $this->actingAs($operator)
            ->getJson("/api/incidents/{$incidentId}/media")
            ->assertOk()
            ->assertJsonCount(3, 'items');
    }

    public function test_processing_media_is_visible_in_operator_and_caller_read_models(): void
    {
        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        DB::table('media')->insert([
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'audio_peer',
                'peer_user_id' => $operator->id,
                'peer_role' => 'operator',
                'peer_label' => $operator->name,
                'path' => '',
                'duration_seconds' => null,
                'metadata_json' => json_encode([
                    'processing' => true,
                    'segment_key' => 'operator-main',
                ]),
                'created_at' => now()->subMinute(),
                'available_at' => null,
            ],
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'caller_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'caller',
                'peer_label' => $caller->name,
                'path' => '',
                'duration_seconds' => null,
                'metadata_json' => json_encode([
                    'processing' => true,
                    'segment_key' => 'caller-cam-1',
                ]),
                'created_at' => now(),
                'available_at' => null,
            ],
        ]);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonCount(2, 'media')
            ->assertJsonFragment([
                'type' => 'audio_peer',
                'processing' => true,
                'path' => null,
            ])
            ->assertJsonFragment([
                'type' => 'citizen_video',
                'processing' => true,
                'path' => null,
            ]);

        $this->actingAs($caller)
            ->getJson("/api/incidents/{$incidentId}/media")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.type', 'caller_video')
            ->assertJsonPath('items.0.processing', true)
            ->assertJsonPath('items.0.path', null);
    }

    public function test_multiple_processing_caller_video_segments_remain_visible_as_separate_artifacts(): void
    {
        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        DB::table('media')->insert([
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'caller_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'caller',
                'peer_label' => $caller->name,
                'path' => '',
                'duration_seconds' => null,
                'metadata_json' => json_encode([
                    'processing' => true,
                    'segment_key' => 'caller-cam-1',
                ]),
                'created_at' => now()->subMinute(),
                'available_at' => null,
            ],
            [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'caller_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'caller',
                'peer_label' => $caller->name,
                'path' => '',
                'duration_seconds' => null,
                'metadata_json' => json_encode([
                    'processing' => true,
                    'segment_key' => 'caller-cam-2',
                ]),
                'created_at' => now(),
                'available_at' => null,
            ],
        ]);

        $this->actingAs($operator)
            ->getJson("/api/operator/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonCount(2, 'media')
            ->assertJsonPath('media.0.metadata.segment_key', 'caller-cam-1')
            ->assertJsonPath('media.1.metadata.segment_key', 'caller-cam-2')
            ->assertJsonPath('media.0.processing', true)
            ->assertJsonPath('media.1.processing', true);

        $this->actingAs($caller)
            ->getJson("/api/incidents/{$incidentId}/media")
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.metadata.segment_key', 'caller-cam-1')
            ->assertJsonPath('items.1.metadata.segment_key', 'caller-cam-2');
    }

    public function test_operator_can_create_upload_and_finalize_processing_media_asset(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $createResponse = $this->actingAs($operator)
            ->postJson('/api/operator/call-sessions/1/media', [
                'type' => 'audio_peer',
                'peer_user_id' => $operator->id,
                'peer_role' => 'operator',
                'peer_label' => $operator->name,
                'mime_type' => 'audio/webm;codecs=opus',
                'extension' => 'weba',
                'track_kind' => 'audio',
                'segment_key' => 'operator-main',
                'started_at' => now()->subSeconds(10)->toIso8601String(),
                'metadata' => [
                    'source' => 'operator-local',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('media.type', 'audio_peer')
            ->assertJsonPath('media.processing', true)
            ->assertJsonPath('media.path', null);

        $mediaId = (int) $createResponse->json('media.id');

        $this->actingAs($operator)
            ->post('/api/operator/media/' . $mediaId . '/chunks', [
                'chunk' => \Illuminate\Http\UploadedFile::fake()->createWithContent('000000.chunk', "\x1A\x45\xDF\xA3".'audio-chunk-1'),
                'chunk_index' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('chunk.chunk_count', 1);

        Storage::disk('local')->assertExists("media-processing/{$incidentId}/1/{$mediaId}/chunks/000000.chunk");

        $finalizeResponse = $this->actingAs($operator)
            ->postJson('/api/operator/media/' . $mediaId . '/finalize', [
                'duration_seconds' => 9,
                'ended_at' => now()->toIso8601String(),
                'extension' => 'weba',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('media.id', $mediaId)
            ->assertJsonPath('media.processing', false)
            ->assertJsonPath('media.duration_seconds', 9);

        $expectedPath = "incidents/{$incidentId}/media/1/{$mediaId}_audio-peer_operator-main.weba";

        $finalizeResponse->assertJsonPath('media.path', $expectedPath);

        Storage::disk('public')->assertExists($expectedPath);
        Storage::disk('local')->assertExists("media-processing/{$incidentId}/1/{$mediaId}/chunks/000000.chunk");

        $this->assertDatabaseHas('media', [
            'id' => $mediaId,
            'incident_id' => $incidentId,
            'type' => 'audio_peer',
            'path' => $expectedPath,
            'duration_seconds' => 9,
        ]);
    }

    public function test_operator_cannot_access_media_pipeline_for_another_operators_call_session(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $this->actingAs($otherOperator)
            ->postJson('/api/operator/call-sessions/1/media', [
                'type' => 'audio_peer',
                'peer_user_id' => $otherOperator->id,
                'peer_role' => 'operator',
                'peer_label' => $otherOperator->name,
                'mime_type' => 'audio/webm;codecs=opus',
                'extension' => 'weba',
                'track_kind' => 'audio',
                'segment_key' => 'operator-other',
            ])
            ->assertNotFound();

        $mediaId = DB::table('media')->insertGetId([
            'incident_id' => $incidentId,
            'call_session_id' => 1,
            'type' => 'audio_peer',
            'peer_user_id' => $operator->id,
            'peer_role' => 'operator',
            'peer_label' => $operator->name,
            'path' => '',
            'duration_seconds' => null,
            'metadata_json' => json_encode([
                'processing' => true,
                'segment_key' => 'operator-main',
            ]),
            'created_at' => now(),
            'available_at' => null,
        ]);

        $this->actingAs($otherOperator)
            ->post('/api/operator/media/' . $mediaId . '/chunks', [
                'chunk' => \Illuminate\Http\UploadedFile::fake()->createWithContent('000000.chunk', 'audio-chunk-1'),
                'chunk_index' => 0,
            ])
            ->assertNotFound();

        $this->actingAs($otherOperator)
            ->postJson('/api/operator/media/' . $mediaId . '/finalize', [
                'duration_seconds' => 4,
                'extension' => 'weba',
            ])
            ->assertNotFound();
    }

    public function test_finalize_returns_conflict_when_no_chunks_were_uploaded(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $createResponse = $this->actingAs($operator)
            ->postJson('/api/operator/call-sessions/1/media', [
                'type' => 'citizen_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'citizen',
                'peer_label' => $caller->name,
                'mime_type' => 'video/webm;codecs=vp8',
                'extension' => 'webm',
                'track_kind' => 'video',
                'segment_key' => 'citizen-cam-1',
            ])
            ->assertCreated();

        $mediaId = (int) $createResponse->json('media.id');

        $this->actingAs($operator)
            ->postJson('/api/operator/media/' . $mediaId . '/finalize', [
                'duration_seconds' => 5,
                'extension' => 'webm',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'No media chunks were uploaded for this asset.');

        $this->assertDatabaseHas('media', [
            'id' => $mediaId,
            'incident_id' => $incidentId,
            'type' => 'citizen_video',
            'peer_role' => 'citizen',
            'path' => '',
        ]);
    }

    public function test_operator_media_endpoint_accepts_citizen_media_aliases(): void
    {
        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $this->actingAs($operator)
            ->postJson('/api/operator/call-sessions/1/media', [
                'type' => 'citizen_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'citizen',
                'peer_label' => $caller->name,
                'mime_type' => 'video/webm;codecs=vp8',
                'extension' => 'webm',
                'track_kind' => 'video',
                'segment_key' => 'citizen-cam-1',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('media.type', 'citizen_video')
            ->assertJsonPath('media.peer_role', 'citizen');

        $this->assertDatabaseHas('media', [
            'incident_id' => $incidentId,
            'type' => 'citizen_video',
            'peer_user_id' => $caller->id,
            'peer_role' => 'citizen',
        ]);
    }

    public function test_finalize_returns_conflict_when_initial_webm_header_chunk_is_missing(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $createResponse = $this->actingAs($operator)
            ->postJson('/api/operator/call-sessions/1/media', [
                'type' => 'audio_peer',
                'peer_user_id' => $caller->id,
                'peer_role' => 'citizen',
                'peer_label' => $caller->name,
                'mime_type' => 'audio/webm;codecs=opus',
                'extension' => 'weba',
                'track_kind' => 'audio',
                'segment_key' => 'citizen-audio-test',
            ])
            ->assertCreated();

        $mediaId = (int) $createResponse->json('media.id');

        $this->actingAs($operator)
            ->post('/api/operator/media/' . $mediaId . '/chunks', [
                'chunk' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                    '000001.chunk',
                    hex2bin('43C38103BF80FB034FB01F9F181D124310955AD3DC0F19DBE2D6C279C25D292D')
                ),
                'chunk_index' => 1,
            ])
            ->assertCreated();

        $this->actingAs($operator)
            ->postJson('/api/operator/media/' . $mediaId . '/finalize', [
                'duration_seconds' => 5,
                'extension' => 'weba',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Incomplete media chunks: no valid WebM header chunk was persisted for this asset.');

        $this->assertDatabaseHas('media', [
            'id' => $mediaId,
            'incident_id' => $incidentId,
            'path' => '',
        ]);
    }

    public function test_finalize_salvages_webm_media_from_first_valid_header_chunk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $createResponse = $this->actingAs($operator)
            ->postJson('/api/operator/call-sessions/1/media', [
                'type' => 'audio_peer',
                'peer_user_id' => $caller->id,
                'peer_role' => 'citizen',
                'peer_label' => $caller->name,
                'mime_type' => 'audio/webm;codecs=opus',
                'extension' => 'weba',
                'track_kind' => 'audio',
                'segment_key' => 'citizen-audio-salvage',
            ])
            ->assertCreated();

        $mediaId = (int) $createResponse->json('media.id');

        $this->actingAs($operator)
            ->post('/api/operator/media/' . $mediaId . '/chunks', [
                'chunk' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                    '000001.chunk',
                    hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D42878104')
                ),
                'chunk_index' => 1,
            ])
            ->assertCreated();

        $this->actingAs($operator)
            ->postJson('/api/operator/media/' . $mediaId . '/finalize', [
                'duration_seconds' => 5,
                'extension' => 'weba',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('media.processing', false);

        $this->assertDatabaseMissing('media', [
            'id' => $mediaId,
            'path' => '',
        ]);
    }

    public function test_media_assembly_complete_endpoint_requires_token_and_creates_media_record(): void
    {
        [$caller, $operator, $otherOperator, $incidentId] = $this->seedIncidentFixture();

        $this->postJson('/api/media/assembly/complete', [
            'incident_id' => $incidentId,
            'call_session_id' => 1,
            'type' => 'caller_video',
            'path' => 'media/caller-video.mp4',
        ])->assertForbidden();

        $this->withHeader('X-Media-Assembly-Token', (string) config('services.media_assembly.token'))
            ->postJson('/api/media/assembly/complete', [
                'incident_id' => $incidentId,
                'call_session_id' => 1,
                'type' => 'citizen_video',
                'peer_user_id' => $caller->id,
                'peer_role' => 'citizen',
                'peer_label' => $caller->name,
                'path' => 'media/caller-video.mp4',
                'duration_seconds' => 18,
                'metadata' => ['codec' => 'h264'],
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('media.type', 'citizen_video');

        $this->assertDatabaseHas('media', [
            'incident_id' => $incidentId,
            'type' => 'citizen_video',
            'peer_role' => 'citizen',
            'path' => 'media/caller-video.mp4',
        ]);
    }

    /**
     * @return array{0:User,1:User,2:User,3:int}
     */
    private function seedIncidentFixture(): array
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $otherOperator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $caller->id,
            'actual_citizen_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('call_sessions')->insert([
            'id' => 1,
            'incident_id' => $incidentId,
            'citizen_id' => $caller->id,
            'status' => 'in_progress',
            'outcome' => 'answered',
            'started_at' => now()->subMinutes(4),
            'answered_at' => now()->subMinutes(4),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(4),
        ]);

        DB::table('call_participants')->insert([
            [
                'call_session_id' => 1,
                'user_id' => $caller->id,
                'participant_role' => 'caller',
                'joined_at' => now()->subMinutes(4),
                'created_at' => now()->subMinutes(4),
            ],
            [
                'call_session_id' => 1,
                'user_id' => $operator->id,
                'participant_role' => 'operator',
                'joined_at' => now()->subMinutes(4),
                'created_at' => now()->subMinutes(4),
            ],
        ]);

        return [$caller, $operator, $otherOperator, $incidentId];
    }
}
