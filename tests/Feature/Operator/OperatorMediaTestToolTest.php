<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use App\Support\IncidentRelay\IncidentRelaySerializer;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OperatorMediaTestToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_operator_can_create_upload_and_finalize_diagnostic_audio_media(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $createResponse = $this->actingAs($operator)
            ->postJson('/api/operator/media-tests', [
                'mime_type' => 'audio/webm;codecs=opus',
                'extension' => 'weba',
                'track_kind' => 'audio',
                'segment_key' => 'operator-diagnostic',
                'started_at' => now()->subSecond()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('media.type', 'operator_media_stream_test')
            ->assertJsonPath('media.metadata.diagnostic', true)
            ->assertJsonPath('media.metadata.diagnostic_type', 'operator_media_stream_storage')
            ->assertJsonPath('media.processing', true);

        $mediaId = $createResponse->json('media.id');

        $this->assertDatabaseHas('media', [
            'id' => $mediaId,
            'incident_id' => null,
            'call_session_id' => null,
            'peer_user_id' => $operator->id,
            'type' => 'operator_media_stream_test',
        ]);

        $chunk = UploadedFile::fake()->createWithContent('diagnostic-000000.weba', "\x1A\x45\xDF\xA3".'operator-audio-chunk');

        $this->actingAs($operator)
            ->post("/api/operator/media-tests/{$mediaId}/chunks", [
                'chunk' => $chunk,
                'chunk_index' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('chunk.chunk_index', 0)
            ->assertJsonPath('chunk.chunk_count', 1);

        Storage::disk('local')->assertExists("media-processing/diagnostics/operator-media-stream-tests/{$mediaId}/chunks/000000.chunk");

        $finalizeResponse = $this->actingAs($operator)
            ->postJson("/api/operator/media-tests/{$mediaId}/finalize", [
                'duration_seconds' => 1,
                'ended_at' => now()->toIso8601String(),
                'extension' => 'weba',
            ])
            ->assertOk()
            ->assertJsonPath('media.processing', false)
            ->assertJsonPath('media.discarded', false);

        $path = $finalizeResponse->json('media.path');
        $this->assertIsString($path);
        $this->assertStringStartsWith("diagnostics/operator-media-stream-tests/{$operator->id}/{$mediaId}_operator-media-stream-test_operator-diagnostic.", $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($finalizeResponse->json('media.playback_url'));
    }

    public function test_diagnostic_media_requires_operator_authentication_and_owner_access(): void
    {
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $otherOperator = User::factory()->create(['role' => UserRole::Operator]);
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);

        $mediaId = DB::table('media')->insertGetId([
            'incident_id' => null,
            'call_session_id' => null,
            'type' => 'operator_media_stream_test',
            'peer_user_id' => $operator->id,
            'peer_role' => 'operator',
            'peer_label' => $operator->name,
            'path' => '',
            'duration_seconds' => null,
            'metadata_json' => json_encode([
                'diagnostic' => true,
                'diagnostic_type' => 'operator_media_stream_storage',
                'created_by_user_id' => $operator->id,
                'processing' => true,
            ]),
            'created_at' => now(),
            'available_at' => null,
        ]);

        $this->postJson('/api/operator/media-tests')->assertUnauthorized();

        $this->actingAs($citizen)
            ->postJson('/api/operator/media-tests')
            ->assertRedirect('/unauthorized');

        $this->actingAs($otherOperator)
            ->postJson("/api/operator/media-tests/{$mediaId}/finalize", ['extension' => 'weba'])
            ->assertNotFound();
    }

    public function test_operator_can_request_realtime_admission_for_owned_diagnostic_media(): void
    {
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $otherOperator = User::factory()->create(['role' => UserRole::Operator]);
        $this->setRealtimeSettings();

        $mediaId = DB::table('media')->insertGetId([
            'incident_id' => null,
            'call_session_id' => null,
            'type' => 'operator_media_stream_test',
            'peer_user_id' => $operator->id,
            'peer_role' => 'operator',
            'peer_label' => $operator->name,
            'path' => '',
            'duration_seconds' => null,
            'metadata_json' => json_encode([
                'diagnostic' => true,
                'diagnostic_type' => 'operator_media_stream_storage',
                'created_by_user_id' => $operator->id,
                'processing' => true,
            ]),
            'created_at' => now(),
            'available_at' => null,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/realtime/admission/operator', [
                'context_type' => 'media_test_ingest',
                'context_id' => $mediaId,
            ])
            ->assertOk()
            ->assertJsonPath('room', "hotline.media.diagnostic.{$mediaId}")
            ->assertJsonPath('project_code', 'prj_HOTLINE_OPERATOR')
            ->assertJsonPath('session.allowed_rooms.0', "hotline.media.diagnostic.{$mediaId}")
            ->assertJsonPath('session.allowed_room_prefixes.0', 'hotline.media.diagnostic.');

        $this->actingAs($otherOperator)
            ->postJson('/api/realtime/admission/operator', [
                'context_type' => 'media_test_ingest',
                'context_id' => $mediaId,
            ])
            ->assertForbidden();
    }

    private function setRealtimeSettings(): void
    {
        DB::table('settings')->upsert([
            [
                'key' => 'realtime_token_signing_secret',
                'value' => json_encode(['value' => str_repeat('a', 64)]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'realtime_client_code',
                'value' => json_encode(['value' => 'clt_PBB_HOTLINE']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'realtime_project_code_media_ingest',
                'value' => json_encode(['value' => 'prj_HOTLINE_OPERATOR']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['key'], ['value', 'updated_at']);
    }

    public function test_operator_can_cancel_diagnostic_media_and_cleanup_chunks(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $mediaId = $this->actingAs($operator)
            ->postJson('/api/operator/media-tests', [
                'mime_type' => 'audio/webm;codecs=opus',
                'extension' => 'weba',
                'track_kind' => 'audio',
            ])
            ->assertCreated()
            ->json('media.id');

        $chunk = UploadedFile::fake()->createWithContent('diagnostic-000000.weba', "\x1A\x45\xDF\xA3".'operator-audio-chunk');

        $this->actingAs($operator)
            ->post("/api/operator/media-tests/{$mediaId}/chunks", [
                'chunk' => $chunk,
                'chunk_index' => 0,
            ])
            ->assertOk();

        $chunkPath = "media-processing/diagnostics/operator-media-stream-tests/{$mediaId}/chunks/000000.chunk";
        Storage::disk('local')->assertExists($chunkPath);

        $this->actingAs($operator)
            ->deleteJson("/api/operator/media-tests/{$mediaId}", ['reason' => 'operator_reset'])
            ->assertOk()
            ->assertJsonPath('media.processing', false)
            ->assertJsonPath('media.discarded', true)
            ->assertJsonPath('media.metadata.discard_reason', 'operator_reset');

        Storage::disk('local')->assertMissing($chunkPath);
        $this->assertDatabaseHas('media', [
            'id' => $mediaId,
            'path' => '',
        ]);
        $this->assertNotNull(DB::table('media')->where('id', $mediaId)->value('available_at'));

        $this->actingAs($operator)
            ->postJson("/api/operator/media-tests/{$mediaId}/finalize", ['extension' => 'weba'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media');
    }

    public function test_diagnostic_media_accepts_audio_only_values(): void
    {
        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $this->actingAs($operator)
            ->postJson('/api/operator/media-tests', [
                'mime_type' => 'video/webm',
                'extension' => 'webm',
                'track_kind' => 'video',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mime_type', 'track_kind']);

        $this->actingAs($operator)
            ->postJson('/api/operator/media-tests', [
                'mime_type' => 'audio/webm',
                'extension' => 'mp4',
                'track_kind' => 'audio',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extension');

        $mediaId = $this->actingAs($operator)
            ->postJson('/api/operator/media-tests', [
                'mime_type' => 'audio/ogg;codecs=opus',
                'extension' => 'ogg',
                'track_kind' => 'audio',
            ])
            ->assertCreated()
            ->json('media.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/media-tests/{$mediaId}/finalize", ['extension' => 'mp3'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extension');
    }

    public function test_diagnostic_media_is_not_exported_as_incident_relay_media_ref(): void
    {
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);

        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => $citizen->name,
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => 'Active',
            'alert_level' => 'Normal',
            'called_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        DB::table('media')->insert([
            'incident_id' => null,
            'call_session_id' => null,
            'type' => 'operator_media_stream_test',
            'peer_user_id' => $operator->id,
            'peer_role' => 'operator',
            'peer_label' => $operator->name,
            'path' => 'diagnostics/operator-media-stream-tests/1/test.weba',
            'duration_seconds' => 2,
            'metadata_json' => json_encode([
                'diagnostic' => true,
                'diagnostic_type' => 'operator_media_stream_storage',
                'created_by_user_id' => $operator->id,
            ]),
            'created_at' => now(),
            'available_at' => now(),
        ]);

        $incident = \App\Domain\Incidents\Models\Incident::query()->findOrFail($incidentId);
        $payload = app(IncidentRelaySerializer::class)->serialize($incident);

        $this->assertSame([], $payload['incident']['media_refs']);
    }
}
