<?php

namespace Tests\Feature\Command;

use App\Domain\IncidentRelay\Models\IncidentRelayDelivery;
use App\Domain\IncidentRelay\Models\IncidentRelayOutbox;
use App\Domain\Incidents\Models\Incident;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use App\Support\IncidentRelay\IncidentRelayOutboxService;
use App\Support\IncidentRelay\IncidentRelaySerializer;
use App\Support\IncidentRelay\IncidentRelaySubmissionService;
use App\Support\Settings\SettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IncidentRelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_serializer_handles_sparse_early_call_incident(): void
    {
        $incident = $this->createIncident([
            'location' => null,
            'latitude' => null,
            'longitude' => null,
        ]);

        $payload = app(IncidentRelaySerializer::class)->serialize($incident);

        $this->assertSame('hotline.incident.upserted', $payload['message_type']);
        $this->assertSame('13:hotline.incident:'.$incident->id, $payload['stable_incident_key']);
        $this->assertStringStartsWith($payload['stable_incident_key'].':', $payload['message_idempotency_key']);
        $this->assertSame((string) $incident->id, $payload['source']['incident_id']);
        $this->assertSame('Active', $payload['incident']['status']);
        $this->assertNull($payload['incident']['location']['label']);
        $this->assertSame([], $payload['incident']['types']);
        $this->assertSame([], $payload['incident']['media_refs']);
    }

    public function test_serializer_includes_location_type_details_team_resources_and_scrubbed_media_refs(): void
    {
        $incident = $this->createRichIncident();

        $payload = app(IncidentRelaySerializer::class)->serialize($incident);

        $this->assertSame('Barangay Apas Gym', $payload['incident']['location']['label']);
        $this->assertSame(10.33333, $payload['incident']['location']['lat']);
        $this->assertSame(123.90001, $payload['incident']['location']['lng']);
        $this->assertSame('Medical Emergency', $payload['incident']['types'][0]['name']);
        $this->assertSame('Patient count', $payload['incident']['details'][0]['field_label']);
        $this->assertSame('Ambulance', $payload['incident']['resources'][0]['resource_type_name']);
        $this->assertSame(2, $payload['incident']['resources'][0]['quantity']);
        $this->assertSame('Rescue Team', $payload['incident']['team_assignments'][0]['team_name']);
        $this->assertSame('accepted', $payload['incident']['team_assignments'][0]['status']);

        $refs = $payload['incident']['media_refs'];
        $this->assertCount(2, $refs);
        $this->assertSame('incident_media', $refs[0]['kind']);
        $this->assertSame('message_attachment', $refs[1]['kind']);
        $this->assertSame('image', $refs[0]['media_type']);
        $this->assertSame('image', $refs[1]['media_type']);
        $this->assertSame('photo.jpg', $refs[0]['safe_filename']);
        $this->assertSame('xray.png', $refs[1]['safe_filename']);
        $this->assertArrayNotHasKey('original_filename', $refs[0]);

        $json = json_encode($refs, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('/storage/', $json);
        $this->assertStringNotContainsString('media/incidents/', $json);
        $this->assertStringNotContainsString('https://hotline', $json);
    }

    public function test_stable_key_remains_same_but_idempotency_key_changes_when_incident_updates(): void
    {
        $incident = $this->createIncident();
        $serializer = app(IncidentRelaySerializer::class);

        $first = $serializer->serialize($incident);

        $incident->forceFill(['updated_at' => now()->addMinute()])->save();
        $second = $serializer->serialize($incident->fresh());

        $this->assertSame($first['stable_incident_key'], $second['stable_incident_key']);
        $this->assertNotSame($first['message_idempotency_key'], $second['message_idempotency_key']);
    }

    public function test_relation_only_resource_update_changes_incident_relay_revision(): void
    {
        $incident = $this->createRichIncident();
        $serializer = app(IncidentRelaySerializer::class);

        $first = $serializer->serialize($incident);
        DB::table('incident_resources_needed')
            ->where('incident_id', $incident->id)
            ->update([
                'quantity_required' => 4,
                'updated_at' => now()->addMinute(),
            ]);
        $second = $serializer->serialize($incident->fresh());

        $this->assertSame($first['stable_incident_key'], $second['stable_incident_key']);
        $this->assertNotSame($first['revision'], $second['revision']);
        $this->assertNotSame($first['message_idempotency_key'], $second['message_idempotency_key']);
        $this->assertSame(4, $second['incident']['resources'][0]['quantity']);
    }

    public function test_outbox_coalesces_repeated_pending_changes_for_same_incident(): void
    {
        $incident = $this->createIncident();
        $service = app(IncidentRelayOutboxService::class);

        $first = $service->markPending($incident);
        $second = $service->markPending($incident);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('incident_relay_outbox', 1);
        $this->assertDatabaseHas('incident_relay_outbox', [
            'incident_id' => $incident->id,
            'status' => IncidentRelayOutbox::STATUS_PENDING,
        ]);
    }

    public function test_relay_envelope_uses_incident_upsert_and_utility_vena_target(): void
    {
        $incident = $this->createRichIncident();
        app(SettingsService::class)->set('incident_relay_enabled', true);
        app(SettingsService::class)->set('relay_url', 'https://relay.pbb.ph');
        app(SettingsService::class)->set('relay_token', 'relay-test-token');

        Http::fake([
            'https://relay.pbb.ph/hub.json' => Http::response($this->hubJson(), 200),
            'https://relay.pbb.ph/api/v1/messages' => Http::response([
                'relay_id' => '01INCIDENTRELAY',
                'message_id' => '01INCIDENTMESSAGE',
                'deliveries_count' => 1,
            ], 201),
        ]);

        $outbox = app(IncidentRelayOutboxService::class)->markPending($incident);
        $delivery = app(IncidentRelaySubmissionService::class)->submit($outbox);

        $this->assertSame(IncidentRelayDelivery::STATUS_SENT, $delivery->status);
        $this->assertDatabaseCount('incident_relay_deliveries', 1);
        $this->assertDatabaseMissing('incident_relay_outbox', ['incident_id' => $incident->id]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://relay.pbb.ph/api/v1/messages'
                && $payload['message_type'] === 'hotline.incident.upserted'
                && $payload['source_system'] === 'hotline.incident'
                && $payload['targets'] === [['id' => '11', 'systems' => ['utility.vena']]]
                && $payload['payload']['incident']['resources'][0]['resource_type_name'] === 'Ambulance';
        });
    }

    public function test_delivery_history_is_append_only_for_new_incident_revisions(): void
    {
        $incident = $this->createIncident();
        app(SettingsService::class)->set('incident_relay_enabled', true);
        app(SettingsService::class)->set('relay_token', 'relay-test-token');

        Http::fake([
            'https://relay.pbb.ph/hub.json' => Http::response($this->hubJson(), 200),
            'https://relay.pbb.ph/api/v1/messages' => Http::response(['message_id' => 'msg-1'], 201),
        ]);

        $outbox = app(IncidentRelayOutboxService::class)->markPending($incident);
        app(IncidentRelaySubmissionService::class)->submit($outbox);

        $incident->forceFill(['updated_at' => now()->addMinutes(5)])->save();
        $outbox = app(IncidentRelayOutboxService::class)->markPending($incident);
        app(IncidentRelaySubmissionService::class)->submit($outbox);

        $this->assertDatabaseCount('incident_relay_deliveries', 2);
        $this->assertSame(2, IncidentRelayDelivery::query()->where('incident_id', $incident->id)->count());
    }

    public function test_unchanged_requeue_does_not_resend_already_sent_payload(): void
    {
        $incident = $this->createIncident();
        app(SettingsService::class)->set('incident_relay_enabled', true);
        app(SettingsService::class)->set('relay_token', 'relay-test-token');

        Http::fake([
            'https://relay.pbb.ph/hub.json' => Http::response($this->hubJson(), 200),
            'https://relay.pbb.ph/api/v1/messages' => Http::response(['message_id' => 'msg-once'], 201),
        ]);

        $outbox = app(IncidentRelayOutboxService::class)->markPending($incident);
        $first = app(IncidentRelaySubmissionService::class)->submit($outbox);
        $outbox = app(IncidentRelayOutboxService::class)->markPending($incident->fresh());
        $second = app(IncidentRelaySubmissionService::class)->submit($outbox);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('incident_relay_deliveries', 1);
        $this->assertDatabaseMissing('incident_relay_outbox', ['incident_id' => $incident->id]);
        $this->assertSame(1, $this->relayPostCount());
    }

    private function createRichIncident(): Incident
    {
        $incident = $this->createIncident([
            'location' => 'Barangay Apas Gym',
            'latitude' => 10.3333333,
            'longitude' => 123.9000123,
            'location_barangay' => 'Apas',
            'location_citymunicipality' => 'Cebu City',
        ]);

        $categoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Life Safety',
            'description' => null,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $typeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $categoryId,
            'name' => 'Medical Emergency',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('incident_incident_type')->insert([
            'incident_id' => $incident->id,
            'incident_type_id' => $typeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $typeId,
            'field_key' => 'patient_count',
            'field_label' => 'Patient count',
            'input_type' => 'number',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('incident_type_details')->insert([
            'incident_id' => $incident->id,
            'incident_type_id' => $typeId,
            'field_id' => $fieldId,
            'field_label' => 'Patient count',
            'field_key' => 'patient_count',
            'field_value' => '2',
            'input_type' => 'number',
            'unit' => 'people',
            'is_required' => false,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Medical Response',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Ambulance',
            'unit_label' => 'units',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('incident_resources_needed')->insert([
            'incident_id' => $incident->id,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 2,
            'notes' => 'Patient transfer requested.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamCategoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Rescue and Medical',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => $teamCategoryId,
            'name' => 'Rescue Team',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('team_assignments')->insert([
            'incident_id' => $incident->id,
            'team_id' => $teamId,
            'assigned_by_operator_id' => $incident->operator_id,
            'status' => 'accepted',
            'assigned_at' => now()->subMinutes(10),
            'accepted_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callSessionId = DB::table('call_sessions')->insertGetId([
            'incident_id' => $incident->id,
            'citizen_id' => $incident->citizen_id,
            'status' => 'ended',
            'outcome' => 'answered',
            'started_at' => now()->subMinutes(20),
            'answered_at' => now()->subMinutes(19),
            'ended_at' => now()->subMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('media')->insert([
            'incident_id' => $incident->id,
            'call_session_id' => $callSessionId,
            'type' => 'image',
            'peer_role' => 'citizen',
            'path' => 'media/incidents/photo.jpg',
            'metadata_json' => json_encode([
                'mime_type' => 'image/jpeg',
                'original_filename' => 'C:\\unsafe\\photo.jpg',
            ]),
            'created_at' => now(),
            'available_at' => now(),
        ]);
        $messageId = DB::table('incident_messages')->insertGetId([
            'incident_id' => $incident->id,
            'sender_id' => $incident->operator_id,
            'sender_role' => 'operator',
            'body' => 'Attachment received.',
            'type' => 'message',
            'created_at' => now(),
        ]);
        DB::table('message_attachments')->insert([
            'message_id' => $messageId,
            'type' => 'image',
            'mime_type' => 'image/png',
            'original_filename' => 'xray.png',
            'stored_path' => 'attachments/xray.png',
            'file_size' => 12345,
            'uploaded_by' => $incident->operator_id,
            'created_at' => now(),
        ]);

        return $incident->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createIncident(array $overrides = []): Incident
    {
        $citizen = User::factory()->create(['role' => UserRole::Citizen]);
        $operator = User::factory()->create(['role' => UserRole::Operator]);

        $id = DB::table('incidents')->insertGetId(array_merge([
            'citizen_id' => $citizen->id,
            'actual_citizen_name' => 'Maria Santos',
            'actual_citizen_relationship' => 'Self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Critical',
            'latitude' => 10.3157,
            'longitude' => 123.8854,
            'location' => 'Apas, Cebu City',
            'location_road' => 'Main Road',
            'location_barangay' => 'Apas',
            'location_citymunicipality' => 'Cebu City',
            'location_country' => 'Philippines',
            'other_details' => 'Needs assistance.',
            'called_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(1),
        ], $overrides));

        return Incident::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function hubJson(): array
    {
        return [
            'hub_id' => '13',
            'relay_hub_id' => '072217013',
            'name' => 'Barangay Apas, Cebu City, Cebu',
            'deployment' => 'barangay',
            'uplinks' => [
                ['hub' => ['id' => '11', 'name' => 'Cebu City, Cebu']],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureIncidentRelayTablesExist();

        Http::fake([
            'https://relay.pbb.ph/hub.json' => Http::response($this->hubJson(), 200),
        ]);
    }

    private function relayPostCount(): int
    {
        return Http::recorded()
            ->filter(fn (array $record): bool => $record[0]->url() === 'https://relay.pbb.ph/api/v1/messages')
            ->count();
    }

    private function ensureIncidentRelayTablesExist(): void
    {
        if (! Schema::hasTable('incident_relay_outbox')) {
            Schema::create('incident_relay_outbox', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->string('message_type', 96)->default('hotline.incident.upserted');
                $table->string('status', 32)->default('pending')->index();
                $table->timestamp('pending_since')->nullable()->index();
                $table->timestamp('last_changed_at')->nullable()->index();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('last_attempted_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique('incident_id');
            });
        }

        if (! Schema::hasTable('incident_relay_deliveries')) {
            Schema::create('incident_relay_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
                $table->string('message_type', 96)->default('hotline.incident.upserted');
                $table->string('status', 32)->default('pending')->index();
                $table->string('stable_incident_key', 255)->index();
                $table->string('revision', 96)->nullable();
                $table->string('idempotency_key', 255)->unique();
                $table->string('payload_hash', 64);
                $table->json('payload_summary_json')->nullable();
                $table->string('relay_id', 64)->nullable()->index();
                $table->string('relay_message_id', 64)->nullable()->index();
                $table->unsignedInteger('deliveries_count')->nullable();
                $table->timestamp('attempted_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('response_json')->nullable();
                $table->timestamps();
                $table->index(['incident_id', 'status']);
            });
        }
    }
}
