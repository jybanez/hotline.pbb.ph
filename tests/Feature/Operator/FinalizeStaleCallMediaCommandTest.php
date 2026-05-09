<?php

namespace Tests\Feature\Operator;

use App\Domain\Shared\Enums\CallOutcome;
use App\Domain\Shared\Enums\CallStatus;
use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinalizeStaleCallMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_finalizes_processing_media_for_ended_call_sessions_with_uploaded_chunks(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$caller, $operator, $incidentId] = $this->seedEndedCallFixture();

        $finalizableMediaId = DB::table('media')->insertGetId([
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
                'extension' => 'weba',
                'segment_key' => 'operator-main',
            ]),
            'created_at' => now()->subMinutes(2),
            'available_at' => null,
        ]);

        $noChunkMediaId = DB::table('media')->insertGetId([
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
                'extension' => 'webm',
                'segment_key' => 'caller-cam-1',
            ]),
            'created_at' => now()->subMinutes(2),
            'available_at' => null,
        ]);

        Storage::disk('local')->put(
            "media-processing/{$incidentId}/1/{$finalizableMediaId}/chunks/000000.chunk",
            'audio-chunk-1'
        );

        $this->artisan('app:finalize-stale-call-media', [
            '--grace-seconds' => 0,
        ])
            ->expectsOutput('Finalize stale call media finished. scanned=2 finalized=1 skipped_no_chunks=1 skipped_not_ended=0 failed=0')
            ->assertExitCode(0);

        $expectedPath = "incidents/{$incidentId}/media/1/{$finalizableMediaId}_audio-peer_operator-main.weba";

        Storage::disk('public')->assertExists($expectedPath);
        Storage::disk('local')->assertMissing("media-processing/{$incidentId}/1/{$finalizableMediaId}/chunks/000000.chunk");

        $this->assertDatabaseHas('media', [
            'id' => $finalizableMediaId,
            'path' => $expectedPath,
        ]);

        $this->assertDatabaseHas('media', [
            'id' => $noChunkMediaId,
            'path' => '',
        ]);
        $this->assertNull(DB::table('media')->where('id', $noChunkMediaId)->value('available_at'));
    }

    public function test_command_skips_processing_media_for_sessions_that_are_not_ended(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$caller, $operator, $incidentId] = $this->seedEndedCallFixture();

        DB::table('call_sessions')->where('id', 1)->update([
            'status' => CallStatus::InProgress->value,
            'ended_at' => null,
        ]);

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
                'extension' => 'weba',
                'segment_key' => 'operator-main',
            ]),
            'created_at' => now()->subMinutes(2),
            'available_at' => null,
        ]);

        Storage::disk('local')->put(
            "media-processing/{$incidentId}/1/{$mediaId}/chunks/000000.chunk",
            'audio-chunk-1'
        );

        $this->artisan('app:finalize-stale-call-media', [
            '--grace-seconds' => 0,
        ])
            ->expectsOutput('Finalize stale call media finished. scanned=1 finalized=0 skipped_no_chunks=0 skipped_not_ended=1 failed=0')
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing("incidents/{$incidentId}/media/1/{$mediaId}_audio-peer_operator-main.weba");
        Storage::disk('local')->assertExists("media-processing/{$incidentId}/1/{$mediaId}/chunks/000000.chunk");
        $this->assertNull(DB::table('media')->where('id', $mediaId)->value('available_at'));
    }

    /**
     * @return array{0:User,1:User,2:int}
     */
    private function seedEndedCallFixture(): array
    {
        $caller = User::factory()->create([
            'role' => UserRole::Caller,
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
            'called_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        DB::table('call_sessions')->insert([
            'id' => 1,
            'incident_id' => $incidentId,
            'caller_id' => $caller->id,
            'status' => CallStatus::Ended->value,
            'outcome' => CallOutcome::EndedByOperator->value,
            'started_at' => now()->subMinutes(4),
            'answered_at' => now()->subMinutes(4),
            'ended_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(4),
            'updated_at' => now()->subMinutes(2),
        ]);

        return [$caller, $operator, $incidentId];
    }
}
