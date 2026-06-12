<?php

namespace Tests\Feature\Internal;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SitrepMediaAccessTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'media-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        app(SettingsService::class)->set('sitrep_media_access_token', self::TOKEN);
    }

    public function test_manifest_returns_authorized_available_media_without_public_storage_paths(): void
    {
        [$incidentId, $mediaId, $attachmentId] = $this->seedMediaFixture();

        $response = $this->postJson('/api/internal/sitrep/media/manifest', [
            'media_refs' => [
                [
                    'kind' => 'incident_media',
                    'source_hub_id' => 'hub-1',
                    'incident_id' => $incidentId,
                    'evidence_ref' => 'resource:oxygen:1',
                    'media_id' => $mediaId,
                ],
                [
                    'kind' => 'message_attachment',
                    'source_hub_id' => 'hub-1',
                    'incident_id' => $incidentId,
                    'attachment_id' => $attachmentId,
                ],
                [
                    'kind' => 'incident_media',
                    'incident_id' => $incidentId,
                    'media_id' => 999999,
                ],
            ],
        ], $this->headers());

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'items')
            ->assertJsonCount(1, 'unavailable')
            ->assertJsonPath('items.0.kind', 'incident_media')
            ->assertJsonPath('items.0.source_hub_id', 'hub-1')
            ->assertJsonPath('items.0.evidence_ref', 'resource:oxygen:1')
            ->assertJsonPath('items.1.kind', 'message_attachment')
            ->assertJsonPath('unavailable.0.reason', 'incident_media_unavailable');

        $payload = $response->json();
        $this->assertStringContainsString('/api/internal/sitrep/media/incident_media/'.$mediaId, $payload['items'][0]['download_url']);
        $this->assertArrayNotHasKey('storage_key', $payload['items'][0]);
        $this->assertStringNotContainsString('/storage/', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('incidents/'.$incidentId.'/media', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('incident-messages/'.$incidentId, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_manifest_rejects_unauthorized_caller(): void
    {
        $this->postJson('/api/internal/sitrep/media/manifest', [
            'media_refs' => [],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('ok', false);
    }

    public function test_download_returns_incident_media_for_authorized_caller(): void
    {
        [$incidentId, $mediaId] = $this->seedMediaFixture();

        $response = $this->get('/api/internal/sitrep/media/incident_media/'.$mediaId.'?incident_id='.$incidentId, $this->headers());

        $response
            ->assertOk()
            ->assertHeader('X-Hotline-Sitrep-Media-Kind', 'incident_media');
        $this->assertSame('incident-media-bytes', $response->streamedContent());
    }

    public function test_download_returns_message_attachment_for_authorized_caller(): void
    {
        [$incidentId, , $attachmentId] = $this->seedMediaFixture();

        $response = $this->get('/api/internal/sitrep/media/message_attachment/'.$attachmentId.'?incident_id='.$incidentId, $this->headers());

        $response
            ->assertOk()
            ->assertHeader('X-Hotline-Sitrep-Media-Kind', 'message_attachment');
        $this->assertSame('attachment-bytes', $response->streamedContent());
    }

    public function test_download_rejects_unauthorized_caller(): void
    {
        [, $mediaId] = $this->seedMediaFixture();

        $this->getJson('/api/internal/sitrep/media/incident_media/'.$mediaId)
            ->assertUnauthorized()
            ->assertJsonPath('ok', false);
    }

    public function test_download_rejects_context_mismatch(): void
    {
        [, $mediaId] = $this->seedMediaFixture();

        $this->getJson('/api/internal/sitrep/media/incident_media/'.$mediaId.'?incident_id=999999', $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function seedMediaFixture(): array
    {
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $incidentId = DB::table('incidents')->insertGetId([
            'citizen_id' => $citizen->id,
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Normal',
            'location' => 'Capitol Site',
            'called_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $callSessionId = DB::table('call_sessions')->insertGetId([
            'incident_id' => $incidentId,
            'citizen_id' => $citizen->id,
            'status' => 'answered',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mediaPath = "incidents/{$incidentId}/media/{$callSessionId}/audio.webm";
        Storage::disk('public')->put($mediaPath, 'incident-media-bytes');
        $mediaId = DB::table('media')->insertGetId([
            'incident_id' => $incidentId,
            'call_session_id' => $callSessionId,
            'type' => 'audio_peer',
            'peer_role' => 'operator',
            'path' => $mediaPath,
            'metadata_json' => json_encode([
                'mime_type' => 'audio/webm',
                'original_filename' => 'operator-audio.webm',
            ]),
            'created_at' => now(),
            'available_at' => now(),
        ]);
        $messageId = DB::table('incident_messages')->insertGetId([
            'incident_id' => $incidentId,
            'sender_id' => $operator->id,
            'sender_role' => 'operator',
            'body' => 'Scene photo attached.',
            'type' => 'message',
            'created_at' => now(),
        ]);
        $attachmentPath = "incident-messages/{$incidentId}/{$messageId}/scene.jpg";
        Storage::disk('public')->put($attachmentPath, 'attachment-bytes');
        $attachmentId = DB::table('message_attachments')->insertGetId([
            'message_id' => $messageId,
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'scene.jpg',
            'stored_path' => $attachmentPath,
            'file_size' => 16,
            'uploaded_by' => $operator->id,
            'created_at' => now(),
        ]);

        return [$incidentId, $mediaId, $attachmentId];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Hotline-Media-Key' => self::TOKEN,
            'X-PBB-Source-System' => 'support.dispatch',
            'X-PBB-Source-Hub-Id' => 'city-hub',
        ];
    }
}
