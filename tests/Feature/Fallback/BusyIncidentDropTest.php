<?php

namespace Tests\Feature\Fallback;

use App\Domain\Fallback\Models\FallbackIncidentDrop;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusyIncidentDropTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        Http::fake();
    }

    public function test_busy_call_attempt_response_offers_fallback_without_creating_call_attempt(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $this->actingAs($citizen)
            ->postJson('/api/citizen/call-attempts', [
                'citizen_latitude' => 10.3306796,
                'citizen_longitude' => 123.8279630,
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'no_available_operator')
            ->assertJsonPath('fallback.available', true)
            ->assertJsonPath('fallback.reason', 'all_operators_busy')
            ->assertJsonPath('fallback.endpoint', '/api/citizen/fallback-drops');

        $this->assertDatabaseCount('call_attempts', 0);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_citizen_can_create_fallback_drop_without_creating_incident(): void
    {
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
            'mobile' => '09171234567',
        ]);

        $response = $this->actingAs($citizen)
            ->postJson('/api/citizen/fallback-drops', [
                'reason' => 'all_operators_busy',
                'quick_category' => 'Flood',
                'short_description' => 'Water is rising near the bridge and we need operator review.',
                'citizen_latitude' => 10.3306796,
                'citizen_longitude' => 123.8279630,
                'citizen_location_accuracy' => 8.5,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('fallback_drop.status', 'new')
            ->assertJsonPath('fallback_drop.reason', 'all_operators_busy')
            ->assertJsonPath('fallback_drop.citizen.mobile', '09171234567');

        $this->assertDatabaseHas('fallback_incident_drops', [
            'citizen_id' => $citizen->id,
            'status' => 'new',
            'quick_category' => 'Flood',
        ]);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('call_attempts', 0);
    }

    public function test_citizen_photo_is_normalized_to_private_webp_metadata(): void
    {
        Storage::fake('local');
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
        ]);

        $response = $this->actingAs($citizen)
            ->post('/api/citizen/fallback-drops', [
                'reason' => 'all_operators_busy',
                'short_description' => 'Photo evidence for blocked route review.',
                'photos' => [
                    UploadedFile::fake()->image('route.jpg', 640, 480),
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('fallback_drop.attachments.0.type', 'image')
            ->assertJsonPath('fallback_drop.attachments.0.stored_mime_type', 'image/webp')
            ->assertJsonMissingPath('fallback_drop.attachments.0.view_url')
            ->assertJsonMissingPath('fallback_drop.attachments.0.download_url')
            ->assertJsonMissingPath('fallback_drop.attachments.0.stored_path');

        $attachment = \DB::table('fallback_incident_drop_attachments')->first();

        $this->assertNotNull($attachment);
        $this->assertSame('image/jpeg', $attachment->original_mime_type);
        $this->assertSame('image/webp', $attachment->stored_mime_type);
        $this->assertStringStartsWith('fallback-incident-drops/', $attachment->stored_path);
        $this->assertStringNotContainsString('/storage/', $attachment->stored_path);
        Storage::disk('local')->assertExists($attachment->stored_path);
    }

    public function test_operator_can_review_private_fallback_photo_without_exposing_storage_path(): void
    {
        Storage::fake('local');
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $created = $this->actingAs($citizen)
            ->post('/api/citizen/fallback-drops', [
                'reason' => 'all_operators_busy',
                'short_description' => 'Photo evidence for review.',
                'photos' => [
                    UploadedFile::fake()->image('route.jpg', 640, 480),
                ],
            ])
            ->assertCreated();

        $dropId = (int) $created->json('fallback_drop.id');
        $attachmentId = (int) $created->json('fallback_drop.attachments.0.id');

        $this->actingAs($operator)
            ->postJson("/api/operator/fallback-drops/{$dropId}/claim")
            ->assertOk();

        $list = $this->actingAs($operator)
            ->getJson('/api/operator/fallback-drops?status=open')
            ->assertOk()
            ->assertJsonMissingPath('items.0.attachments.0.stored_path')
            ->assertJsonPath('items.0.attachments.0.view_url', "/api/operator/fallback-drops/{$dropId}/attachments/{$attachmentId}");

        $this->assertSame("/api/operator/fallback-drops/{$dropId}/attachments/{$attachmentId}?download=1", $list->json('items.0.attachments.0.download_url'));

        $photo = $this->actingAs($operator)
            ->get("/api/operator/fallback-drops/{$dropId}/attachments/{$attachmentId}")
            ->assertOk();

        $this->assertStringStartsWith('image/webp', (string) $photo->headers->get('content-type'));

        $this->actingAs($operator)
            ->get("/api/operator/fallback-drops/{$dropId}/attachments/{$attachmentId}?download=1")
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_non_operators_and_other_citizens_cannot_access_fallback_photo(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => UserRole::Citizen]);
        $otherCitizen = User::factory()->create(['role' => UserRole::Citizen]);

        $created = $this->actingAs($owner)
            ->post('/api/citizen/fallback-drops', [
                'reason' => 'all_operators_busy',
                'short_description' => 'Private photo evidence for review.',
                'photos' => [
                    UploadedFile::fake()->image('route.jpg', 640, 480),
                ],
            ])
            ->assertCreated()
            ->assertJsonMissingPath('fallback_drop.attachments.0.view_url')
            ->assertJsonMissingPath('fallback_drop.attachments.0.download_url')
            ->assertJsonMissingPath('fallback_drop.attachments.0.stored_path');

        $dropId = (int) $created->json('fallback_drop.id');
        $attachmentId = (int) $created->json('fallback_drop.attachments.0.id');

        $this->actingAs($owner)
            ->getJson("/api/operator/fallback-drops/{$dropId}/attachments/{$attachmentId}")
            ->assertRedirect('/unauthorized');

        $this->actingAs($otherCitizen)
            ->getJson("/api/operator/fallback-drops/{$dropId}/attachments/{$attachmentId}")
            ->assertRedirect('/unauthorized');

        $this->actingAs($otherCitizen)
            ->getJson("/api/citizen/fallback-drops/{$dropId}")
            ->assertNotFound();
    }

    public function test_other_citizen_cannot_read_fallback_drop(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Citizen]);
        $other = User::factory()->create(['role' => UserRole::Citizen]);
        $drop = FallbackIncidentDrop::query()->create([
            'citizen_id' => $owner->id,
            'status' => 'new',
            'reason' => 'all_operators_busy',
            'short_description' => 'Needs operator review.',
        ]);

        $this->actingAs($other)
            ->getJson("/api/citizen/fallback-drops/{$drop->id}")
            ->assertNotFound();
    }

    public function test_operator_claim_is_concurrency_safe(): void
    {
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operatorA = User::factory()->create(['role' => UserRole::Operator]);
        $operatorB = User::factory()->create(['role' => UserRole::Operator]);
        $drop = FallbackIncidentDrop::query()->create([
            'citizen_id' => $citizen->id,
            'status' => 'new',
            'reason' => 'all_operators_busy',
            'short_description' => 'Needs operator review.',
        ]);

        $this->actingAs($operatorA)
            ->postJson("/api/operator/fallback-drops/{$drop->id}/claim")
            ->assertOk()
            ->assertJsonPath('fallback_drop.status', 'claimed')
            ->assertJsonPath('fallback_drop.claimed_by_operator.id', $operatorA->id);

        $this->actingAs($operatorB)
            ->postJson("/api/operator/fallback-drops/{$drop->id}/claim")
            ->assertStatus(409);

        $this->assertDatabaseHas('fallback_incident_drops', [
            'id' => $drop->id,
            'claimed_by_operator_id' => $operatorA->id,
        ]);
    }

    public function test_operator_conversion_creates_exactly_one_incident_and_is_idempotent(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('alert_level', 'Critical');
        $citizen = User::factory()->create([
            'role' => UserRole::Citizen,
            'name' => 'Maria Santos',
        ]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);
        $drop = FallbackIncidentDrop::query()->create([
            'citizen_id' => $citizen->id,
            'status' => 'new',
            'reason' => 'all_operators_busy',
            'quick_category' => 'Rescue',
            'short_description' => 'Family trapped near the creek.',
            'citizen_latitude' => 10.3306796,
            'citizen_longitude' => 123.8279630,
        ]);

        $first = $this->actingAs($operator)
            ->postJson("/api/operator/fallback-drops/{$drop->id}/convert")
            ->assertOk()
            ->assertJsonPath('fallback_drop.status', 'converted');

        $incidentId = $first->json('fallback_drop.converted_incident_id');
        $this->assertNotNull($incidentId);

        $this->actingAs($operator)
            ->postJson("/api/operator/fallback-drops/{$drop->id}/convert")
            ->assertOk()
            ->assertJsonPath('fallback_drop.converted_incident_id', $incidentId);

        $this->assertDatabaseCount('incidents', 1);
        $this->assertDatabaseHas('incidents', [
            'id' => $incidentId,
            'citizen_id' => $citizen->id,
            'operator_id' => $operator->id,
            'status' => 'Active',
            'alert_level' => 'Critical',
        ]);
        $this->assertDatabaseHas('fallback_incident_drops', [
            'id' => $drop->id,
            'status' => 'converted',
            'converted_incident_id' => $incidentId,
        ]);
    }

    public function test_realtime_failure_does_not_fail_authoritative_drop_creation(): void
    {
        app(SettingsService::class)->set('realtime_backend_ingress_secret', 'secret');
        Http::fake([
            '*' => Http::response(['ok' => false], 500),
        ]);

        $citizen = User::factory()->create(['role' => UserRole::Citizen]);

        $this->actingAs($citizen)
            ->postJson('/api/citizen/fallback-drops', [
                'reason' => 'all_operators_busy',
                'short_description' => 'Realtime can fail but this drop should persist.',
            ])
            ->assertCreated()
            ->assertJsonPath('fallback_drop.status', 'new');

        $this->assertDatabaseHas('fallback_incident_drops', [
            'citizen_id' => $citizen->id,
            'status' => 'new',
        ]);
    }
}
