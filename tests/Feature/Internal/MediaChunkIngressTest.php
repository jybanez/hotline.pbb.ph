<?php

namespace Tests\Feature\Internal;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaChunkIngressTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_media_chunk_ingest_stores_chunk_for_authorized_operator_sender(): void
    {
        Storage::fake('local');
        [$operator, $mediaId] = $this->seedCallMediaFixture();
        $this->setMediaIngestSecret('test-media-secret');

        $response = $this->postJson('/api/internal/media/chunks', [
            'incident_id' => 1,
            'call_session_id' => 1,
            'media_id' => $mediaId,
            'type' => 'audio_peer',
            'peer_user_id' => $operator->id,
            'peer_role' => 'operator',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm;codecs=opus',
            'extension' => 'weba',
            'segment_key' => 'operator-audio-segment',
            'chunk_index' => 0,
            'total_bytes' => 12,
            'chunk_data' => base64_encode('hello chunk'),
            'sender_user_id' => $operator->id,
            'project_code' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
            'room' => 'call.session.1',
        ], [
            'X-Hotline-Media-Ingest-Secret' => 'test-media-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('ok', true);

        $chunkPath = $response->json('chunk.chunk_path');
        $this->assertNotEmpty($chunkPath);
        Storage::disk('local')->assertExists($chunkPath);
        $this->assertSame('hello chunk', Storage::disk('local')->get($chunkPath));
    }

    public function test_internal_media_chunk_ingest_rejects_invalid_secret(): void
    {
        Storage::fake('local');
        [, $mediaId] = $this->seedCallMediaFixture();
        $this->setMediaIngestSecret('expected-secret');

        $this->postJson('/api/internal/media/chunks', [
            'incident_id' => 1,
            'call_session_id' => 1,
            'media_id' => $mediaId,
            'type' => 'audio_peer',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm;codecs=opus',
            'extension' => 'weba',
            'chunk_index' => 0,
            'chunk_data' => base64_encode('hello chunk'),
            'sender_user_id' => 1,
        ], [
            'X-Hotline-Media-Ingest-Secret' => 'wrong-secret',
        ])->assertStatus(401)
            ->assertJsonPath('message', 'Invalid media ingest secret.');
    }

    public function test_internal_media_chunk_ingest_accepts_realtime_forwarded_wrapper_shape(): void
    {
        Storage::fake('local');
        [$operator, $mediaId] = $this->seedCallMediaFixture();
        $this->setMediaIngestSecret('test-media-secret');

        $response = $this->postJson('/api/internal/media/chunks', [
            'type' => 'media.chunk.publish',
            'room' => 'call.session.1',
            'client_code' => 'clt_hotline',
            'project_code' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
            'payload' => [
                'incident_id' => 1,
                'call_session_id' => 1,
                'media_id' => $mediaId,
                'type' => 'audio_peer',
                'peer_user_id' => $operator->id,
                'peer_role' => 'operator',
                'track_kind' => 'audio',
                'mime_type' => 'audio/webm;codecs=opus',
                'extension' => 'weba',
                'segment_key' => 'operator-audio-segment',
                'chunk_index' => 1,
                'total_bytes' => 13,
                'chunk_data' => base64_encode('wrapped chunk'),
            ],
            'meta' => [
                'sender' => [
                    'user_id' => $operator->id,
                    'display_name' => $operator->name,
                    'project_code' => 'prj_01KMXG0AXVRCG0WGZMMYKTVPZV',
                    'app_code' => 'clt_hotline',
                ],
            ],
        ], [
            'X-Realtime-Media-Ingest-Secret' => 'test-media-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('ok', true);

        $chunkPath = $response->json('chunk.chunk_path');
        $this->assertNotEmpty($chunkPath);
        Storage::disk('local')->assertExists($chunkPath);
        $this->assertSame('wrapped chunk', Storage::disk('local')->get($chunkPath));
    }

    public function test_internal_media_chunk_ingest_accepts_citizen_media_aliases(): void
    {
        Storage::fake('local');
        [$operator, $mediaId] = $this->seedCallMediaFixture('caller_video', 'caller');
        $this->setMediaIngestSecret('test-media-secret');

        $response = $this->postJson('/api/internal/media/chunks', [
            'incident_id' => 1,
            'call_session_id' => 1,
            'media_id' => $mediaId,
            'type' => 'citizen_video',
            'peer_role' => 'citizen',
            'track_kind' => 'video',
            'mime_type' => 'video/webm;codecs=vp8',
            'extension' => 'webm',
            'segment_key' => 'caller-video-segment',
            'chunk_index' => 0,
            'chunk_data' => base64_encode('video chunk'),
            'sender_user_id' => $operator->id,
            'room' => 'call.session.1',
        ], [
            'X-Hotline-Media-Ingest-Secret' => 'test-media-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('ok', true);

        $chunkPath = $response->json('chunk.chunk_path');
        $this->assertNotEmpty($chunkPath);
        Storage::disk('local')->assertExists($chunkPath);
        $this->assertSame('video chunk', Storage::disk('local')->get($chunkPath));
    }

    public function test_internal_media_chunk_ingest_accepts_legacy_caller_aliases_for_citizen_media(): void
    {
        Storage::fake('local');
        [$operator, $mediaId] = $this->seedCallMediaFixture('citizen_video', 'citizen');
        $this->setMediaIngestSecret('test-media-secret');

        $response = $this->postJson('/api/internal/media/chunks', [
            'incident_id' => 1,
            'call_session_id' => 1,
            'media_id' => $mediaId,
            'type' => 'caller_video',
            'peer_role' => 'caller',
            'track_kind' => 'video',
            'mime_type' => 'video/webm;codecs=vp8',
            'extension' => 'webm',
            'segment_key' => 'citizen-video-segment',
            'chunk_index' => 0,
            'chunk_data' => base64_encode('new video chunk'),
            'sender_user_id' => $operator->id,
            'room' => 'call.session.1',
        ], [
            'X-Hotline-Media-Ingest-Secret' => 'test-media-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('ok', true);

        $chunkPath = $response->json('chunk.chunk_path');
        $this->assertNotEmpty($chunkPath);
        Storage::disk('local')->assertExists($chunkPath);
        $this->assertSame('new video chunk', Storage::disk('local')->get($chunkPath));
    }

    public function test_internal_media_chunk_ingest_rejects_non_operator_sender_for_call_session(): void
    {
        Storage::fake('local');
        [, $mediaId] = $this->seedCallMediaFixture();
        $intruder = User::factory()->create([
            'role' => UserRole::Operator,
            'status' => UserStatus::Active,
        ]);
        $this->setMediaIngestSecret('test-media-secret');

        $this->postJson('/api/internal/media/chunks', [
            'incident_id' => 1,
            'call_session_id' => 1,
            'media_id' => $mediaId,
            'type' => 'audio_peer',
            'track_kind' => 'audio',
            'mime_type' => 'audio/webm;codecs=opus',
            'extension' => 'weba',
            'chunk_index' => 0,
            'chunk_data' => base64_encode('hello chunk'),
            'sender_user_id' => $intruder->id,
        ], [
            'X-Hotline-Media-Ingest-Secret' => 'test-media-secret',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Media ingest sender is not allowed for this call session.');
    }

    /**
     * @return array{0:User,1:int}
     */
    private function seedCallMediaFixture(string $mediaType = 'audio_peer', string $peerRole = 'operator'): array
    {
        $caller = User::factory()->create([
            'role' => UserRole::Citizen,
            'status' => UserStatus::Active,
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::Operator,
            'status' => UserStatus::Active,
        ]);

        DB::table('incidents')->insert([
            'id' => 1,
            'caller_id' => $caller->id,
            'actual_caller_name' => $caller->name,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('call_sessions')->insert([
            'id' => 1,
            'incident_id' => 1,
            'caller_id' => $caller->id,
            'status' => 'in_progress',
            'outcome' => 'answered',
            'started_at' => now()->subMinutes(1),
            'answered_at' => now()->subMinutes(1),
            'created_at' => now()->subMinutes(1),
            'updated_at' => now()->subMinutes(1),
        ]);

        DB::table('call_participants')->insert([
            'call_session_id' => 1,
            'user_id' => $operator->id,
            'participant_role' => UserRole::Operator->value,
            'joined_at' => now()->subMinutes(1),
            'created_at' => now()->subMinutes(1),
        ]);

        $peer = in_array($peerRole, UserRole::citizenValues(), true) ? $caller : $operator;
        $isCitizenVideo = in_array($mediaType, ['caller_video', 'citizen_video'], true);
        $segmentKey = $isCitizenVideo
            ? ($mediaType === 'citizen_video' ? 'citizen-video-segment' : 'caller-video-segment')
            : 'operator-audio-segment';

        $mediaId = DB::table('media')->insertGetId([
            'incident_id' => 1,
            'call_session_id' => 1,
            'type' => $mediaType,
            'peer_user_id' => $peer->id,
            'peer_role' => $peerRole,
            'peer_label' => $peer->name,
            'path' => '',
            'duration_seconds' => null,
            'metadata_json' => json_encode([
                'processing' => true,
                'segment_key' => $segmentKey,
                'extension' => $isCitizenVideo ? 'webm' : 'weba',
                'track_kind' => $isCitizenVideo ? 'video' : 'audio',
            ]),
            'created_at' => now()->subMinute(),
            'available_at' => null,
        ]);

        return [$operator, $mediaId];
    }

    private function setMediaIngestSecret(string $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'realtime_media_ingest_secret'],
            ['value' => ['value' => $value]],
        );
    }
}
