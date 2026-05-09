<?php

namespace Tests\Feature\Command;

use App\Domain\Shared\Enums\IncidentStatus;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitrepGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        Carbon::setTestNow(Carbon::parse('2026-04-29 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_user_can_generate_incident_centered_sitrep_snapshot(): void
    {
        [$command, $incidentId, $resourceTypeId, $incidentTypeId] = $this->seedSitrepScenario();

        $response = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Cebu Flooding SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
            'status' => 'draft',
            'visibility' => 'private',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('sitrep.title', 'Cebu Flooding SITREP')
            ->assertJsonPath('sitrep.summary.supporting_metrics.total_incidents', 1)
            ->assertJsonPath('sitrep.summary.supporting_metrics.total_call_sessions', 2)
            ->assertJsonPath('sitrep.summary.supporting_metrics.incident_type_mentions', 2)
            ->assertJsonPath('sitrep.summary.supporting_metrics.resource_need_units', 3)
            ->assertJsonPath('sitrep.situation.multi_type_incident_count', 1)
            ->assertJsonPath('sitrep.needs.total_quantity_requested', 3)
            ->assertJsonPath('sitrep.needs.items.0.resource', 'Rescue Boat')
            ->assertJsonPath('sitrep.population.numeric_total', 5)
            ->assertJsonPath('sitrep.privacy_redactions.caller_phone_numbers', 'redacted');

        $this->assertDatabaseHas('sitrep_reports', [
            'title' => 'Cebu Flooding SITREP',
            'prepared_by_user_id' => $command->id,
            'status' => 'draft',
            'visibility' => 'private',
        ]);

        $report = DB::table('sitrep_reports')->first();
        $sourceSnapshot = json_decode($report->source_snapshot_json, true);

        $this->assertSame([$incidentId], $sourceSnapshot['incident_ids']);
        $this->assertSame(1, $sourceSnapshot['adapter_version']);
        $this->assertDatabaseHas('incident_resources_needed', [
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
        ]);
    }

    public function test_public_sitrep_route_only_exposes_published_public_reports(): void
    {
        [$command] = $this->seedSitrepScenario();

        $privateReportId = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Private SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
            'status' => 'draft',
            'visibility' => 'private',
        ])->json('sitrep.id');

        $publicReportId = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Public SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
            'status' => 'published',
            'visibility' => 'public',
        ])->json('sitrep.id');

        $this->get("/sitrep/{$privateReportId}")->assertNotFound();

        $this->get("/sitrep/{$publicReportId}")
            ->assertOk()
            ->assertSee('Public SITREP')
            ->assertSee('Current Situation Picture')
            ->assertDontSee('09170000003');

        $this->actingAs($command)->get("/command/sitreps/{$privateReportId}/preview")
            ->assertOk()
            ->assertSee('Preview only. This SITREP is not public unless status is published and visibility is public.');

        $this->actingAs($command)->get("/command/sitreps/{$publicReportId}/preview")
            ->assertOk()
            ->assertDontSee('Preview only. This SITREP is not public unless status is published and visibility is public.');
    }

    public function test_public_home_bootstrap_lists_only_published_public_sitreps(): void
    {
        $this->createSitrepReport([
            'sequence_number' => 1,
            'title' => 'Draft Public SITREP',
            'status' => 'draft',
            'visibility' => 'public',
            'generated_at' => now()->addHours(3),
        ]);
        $this->createSitrepReport([
            'sequence_number' => 2,
            'title' => 'Published Private SITREP',
            'status' => 'published',
            'visibility' => 'private',
            'generated_at' => now()->addHours(2),
        ]);
        $older = $this->createSitrepReport([
            'sequence_number' => 3,
            'title' => 'Older Public SITREP',
            'status' => 'published',
            'visibility' => 'public',
            'generated_at' => now()->subDay(),
            'summary_json' => ['headline' => 'Older public operating picture.'],
        ]);
        $latest = $this->createSitrepReport([
            'sequence_number' => 4,
            'title' => 'Latest Public SITREP',
            'coverage_area' => 'Cebu City',
            'status' => 'published',
            'visibility' => 'public',
            'generated_at' => now(),
            'alert_level' => 'Critical',
            'summary_json' => ['headline' => 'Latest public operating picture.'],
        ]);

        $this->getJson('/api/bootstrap?surface=public')
            ->assertOk()
            ->assertJsonPath('surface_payload.sitrep.latest.id', $latest->id)
            ->assertJsonPath('surface_payload.sitrep.latest.title', 'Latest Public SITREP')
            ->assertJsonPath('surface_payload.sitrep.latest_html', view('pages.sitrep.partials.document', [
                'sitrep' => $latest,
                'isPreview' => false,
            ])->render())
            ->assertJsonPath('surface_payload.sitrep.latest.report_number', '#0004')
            ->assertJsonPath('surface_payload.sitrep.latest.coverage_area', 'Cebu City')
            ->assertJsonPath('surface_payload.sitrep.latest.alert_level', 'Critical')
            ->assertJsonPath('surface_payload.sitrep.latest.headline', 'Latest public operating picture.')
            ->assertJsonPath('surface_payload.sitrep.latest.public_url', route('sitrep.public.show', ['sitrep' => $latest]))
            ->assertJsonPath('surface_payload.sitrep.archive.0.id', $older->id)
            ->assertJsonPath('surface_payload.sitrep.archive.0.title', 'Older Public SITREP')
            ->assertJsonMissing(['title' => 'Draft Public SITREP'])
            ->assertJsonMissing(['title' => 'Published Private SITREP']);
    }

    public function test_public_home_bootstrap_provides_current_full_public_sitrep_and_archive(): void
    {
        $this->createSitrepReport([
            'sequence_number' => 1,
            'title' => 'Draft Home SITREP',
            'status' => 'draft',
            'visibility' => 'private',
            'generated_at' => now()->addHours(3),
        ]);
        $this->createSitrepReport([
            'sequence_number' => 2,
            'title' => 'Private Home SITREP',
            'status' => 'published',
            'visibility' => 'private',
            'generated_at' => now()->addHours(2),
        ]);
        $older = $this->createSitrepReport([
            'sequence_number' => 3,
            'title' => 'Archived Home SITREP',
            'status' => 'published',
            'visibility' => 'public',
            'generated_at' => now()->subDay(),
        ]);
        $latest = $this->createSitrepReport([
            'sequence_number' => 4,
            'title' => 'Current Full Home SITREP',
            'coverage_area' => 'Cebu City',
            'status' => 'published',
            'visibility' => 'public',
            'generated_at' => now(),
            'summary_json' => [
                'headline' => 'Current full public operating picture.',
                'posture_label' => 'Critical response',
                'supporting_metrics' => [
                    'total_incidents' => 4,
                    'active_at_close' => 2,
                ],
                'priority_watch_items' => ['River level monitoring'],
            ],
            'situation_json' => [
                'narrative' => 'Flooding remains active in low-lying areas.',
                'locations' => [['area' => 'Cebu City', 'count' => 4]],
                'incident_types' => [['type' => 'Flooding', 'count' => 4]],
            ],
            'needs_json' => [
                'items' => [[
                    'resource' => 'Rescue Boat',
                    'quantity_requested' => 2,
                    'incident_count' => 1,
                    'status' => 'open',
                ]],
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-surface="public"', false)
            ->assertSee('data-api-bootstrap-url="/api/bootstrap?surface=public"', false)
            ->assertDontSee('Current Full Home SITREP')
            ->assertDontSee('Draft Home SITREP')
            ->assertDontSee('Private Home SITREP');

        $bootstrap = $this->getJson('/api/bootstrap?surface=public')
            ->assertOk()
            ->assertJsonPath('surface_payload.sitrep.latest.id', $latest->id)
            ->assertJsonPath('surface_payload.sitrep.latest.title', 'Current Full Home SITREP')
            ->assertJsonPath('surface_payload.sitrep.latest.report_number', '#0004')
            ->assertJsonPath('surface_payload.sitrep.latest.coverage_area', 'Cebu City')
            ->assertJsonPath('surface_payload.sitrep.latest.headline', 'Current full public operating picture.')
            ->assertJsonPath('surface_payload.sitrep.archive.0.id', $older->id)
            ->assertJsonPath('surface_payload.sitrep.archive.0.title', 'Archived Home SITREP')
            ->assertJsonMissing(['title' => 'Draft Home SITREP'])
            ->assertJsonMissing(['title' => 'Private Home SITREP']);

        $latestHtml = $bootstrap->json('surface_payload.sitrep.latest_html');
        $this->assertSame(view('pages.sitrep.partials.document', [
            'sitrep' => $latest,
            'isPreview' => false,
        ])->render(), $latestHtml);
        $this->assertStringContainsString('sitrep-document', $latestHtml);
        $this->assertStringContainsString('Current full public operating picture.', $latestHtml);
        $this->assertStringContainsString('Rescue Boat', $latestHtml);
        $this->assertStringNotContainsString('Draft Home SITREP', $latestHtml);
        $this->assertStringNotContainsString('Private Home SITREP', $latestHtml);

        $this->assertTrue($latest->isPubliclyVisible());
    }

    public function test_public_home_bootstrap_returns_empty_sitrep_state_when_none_is_public(): void
    {
        $this->createSitrepReport([
            'sequence_number' => 1,
            'title' => 'Private Only SITREP',
            'status' => 'published',
            'visibility' => 'private',
        ]);

        $this->getJson('/api/bootstrap?surface=public')
            ->assertOk()
            ->assertJsonPath('surface_payload.sitrep.latest', null)
            ->assertJsonPath('surface_payload.sitrep.latest_html', null)
            ->assertJsonCount(0, 'surface_payload.sitrep.archive')
            ->assertJsonMissing(['title' => 'Private Only SITREP']);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-surface="public"', false)
            ->assertDontSee('Private Only SITREP');
    }

    public function test_command_user_can_publish_and_toggle_sitrep_visibility(): void
    {
        [$command] = $this->seedSitrepScenario();

        $reportId = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Publishable SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
            'status' => 'draft',
            'visibility' => 'private',
        ])->json('sitrep.id');

        $this->actingAs($command)->patchJson("/api/command/sitreps/{$reportId}", [
            'visibility' => 'public',
        ])->assertUnprocessable();

        $this->actingAs($command)->patchJson("/api/command/sitreps/{$reportId}", [
            'status' => 'published',
        ])
            ->assertOk()
            ->assertJsonPath('sitrep.status', 'published')
            ->assertJsonPath('sitrep.visibility', 'private');

        $this->assertDatabaseHas('sitrep_reports', [
            'id' => $reportId,
            'reviewed_by_user_id' => $command->id,
        ]);

        $this->actingAs($command)->patchJson("/api/command/sitreps/{$reportId}", [
            'visibility' => 'public',
        ])
            ->assertOk()
            ->assertJsonPath('sitrep.status', 'published')
            ->assertJsonPath('sitrep.visibility', 'public');

        $this->actingAs($command)->patchJson("/api/command/sitreps/{$reportId}", [
            'visibility' => 'private',
        ])
            ->assertOk()
            ->assertJsonPath('sitrep.status', 'published')
            ->assertJsonPath('sitrep.visibility', 'private');
    }

    public function test_sitrep_snapshot_does_not_change_when_source_incident_changes(): void
    {
        [$command, $incidentId] = $this->seedSitrepScenario();

        $reportId = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Stable Snapshot SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
        ])->json('sitrep.id');

        DB::table('incidents')->where('id', $incidentId)->update([
            'status' => IncidentStatus::Resolved->value,
            'updated_at' => now()->addMinute(),
        ]);

        $this->actingAs($command)
            ->getJson("/api/command/sitreps/{$reportId}")
            ->assertOk()
            ->assertJsonPath('sitrep.summary.supporting_metrics.active_at_close', 1)
            ->assertJsonPath('sitrep.source_snapshot.incident_ids.0', $incidentId);
    }

    public function test_command_user_can_list_and_preview_generated_sitreps(): void
    {
        [$command] = $this->seedSitrepScenario();
        $anotherCommand = User::factory()->create([
            'role' => UserRole::Command,
        ]);

        $reportId = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Command Shared SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
            'status' => 'draft',
            'visibility' => 'private',
        ])->json('sitrep.id');

        $this->actingAs($anotherCommand)
            ->getJson('/api/command/sitreps')
            ->assertOk()
            ->assertJsonPath('items.0.id', $reportId)
            ->assertJsonPath('items.0.title', 'Command Shared SITREP')
            ->assertJsonStructure([
                'items' => [
                    [
                        'id',
                        'sequence_number',
                        'title',
                        'coverage_area',
                        'period_started_at',
                        'period_ended_at',
                        'generated_at',
                        'status',
                        'visibility',
                        'alert_level',
                        'public_url',
                        'preview_url',
                    ],
                ],
            ]);

        $this->actingAs($anotherCommand)
            ->get("/command/sitreps/{$reportId}/preview")
            ->assertOk()
            ->assertSee('Command Shared SITREP');

        $this->actingAs($anotherCommand)
            ->get("/command/sitreps/{$reportId}/download/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function seedSitrepScenario(): array
    {
        $caller = User::factory()->create([
            'name' => 'PBB Caller',
            'mobile' => '09170000003',
            'role' => UserRole::Citizen,
        ]);

        $command = User::factory()->create([
            'role' => UserRole::Command,
        ]);

        $operator = User::factory()->create([
            'role' => UserRole::Operator,
        ]);

        $incidentCategoryId = DB::table('incident_categories')->insertGetId([
            'name' => 'Rescue',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $incidentCategoryId,
            'name' => 'Flood rescue',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondaryIncidentTypeId = DB::table('incident_types')->insertGetId([
            'incident_category_id' => $incidentCategoryId,
            'name' => 'Medical assist',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $peopleFieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'people_stranded',
            'field_label' => 'People stranded',
            'input_type' => 'number',
            'unit' => 'people',
            'is_required' => false,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $damageFieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'damaged_house',
            'field_label' => 'Damaged house',
            'input_type' => 'text',
            'unit' => null,
            'is_required' => false,
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceCategoryId = DB::table('resource_type_categories')->insertGetId([
            'name' => 'Transport',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resourceTypeId = DB::table('resource_types')->insertGetId([
            'category_id' => $resourceCategoryId,
            'name' => 'Rescue Boat',
            'unit_label' => 'boats',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamCategoryId = DB::table('team_categories')->insertGetId([
            'name' => 'Rescue Teams',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teamId = DB::table('teams')->insertGetId([
            'team_category_id' => $teamCategoryId,
            'name' => 'Team Alpha',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $incidentId = DB::table('incidents')->insertGetId([
            'caller_id' => $caller->id,
            'actual_caller_name' => 'Maria Santos',
            'actual_caller_relationship' => 'self',
            'operator_id' => $operator->id,
            'status' => IncidentStatus::Active->value,
            'alert_level' => 'Elevated',
            'latitude' => 10.3304927,
            'longitude' => 123.8825668,
            'location_barangay' => 'Guadalupe',
            'location_citymunicipality' => 'Cebu City',
            'called_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHour(),
        ]);

        DB::table('incident_incident_type')->insert([
            [
                'incident_id' => $incidentId,
                'incident_type_id' => $incidentTypeId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'incident_id' => $incidentId,
                'incident_type_id' => $secondaryIncidentTypeId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('incident_type_details')->insert([
            [
                'incident_id' => $incidentId,
                'incident_type_id' => $incidentTypeId,
                'field_id' => $peopleFieldId,
                'field_key' => 'people_stranded',
                'field_label' => 'People stranded',
                'field_value' => '5',
                'input_type' => 'number',
                'unit' => 'people',
                'is_required' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'incident_id' => $incidentId,
                'incident_type_id' => $incidentTypeId,
                'field_id' => $damageFieldId,
                'field_key' => 'damaged_house',
                'field_label' => 'Damaged house',
                'field_value' => 'Roof damage reported',
                'input_type' => 'text',
                'unit' => null,
                'is_required' => false,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('incident_resources_needed')->insert([
            'incident_id' => $incidentId,
            'incident_type_id' => $incidentTypeId,
            'resource_type_id' => $resourceTypeId,
            'quantity_required' => 3,
            'notes' => 'Needed for flooded street extraction.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('team_assignments')->insert([
            'incident_id' => $incidentId,
            'team_id' => $teamId,
            'assigned_by_operator_id' => $operator->id,
            'status' => 'Assigned',
            'assigned_at' => now()->subMinutes(90),
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(90),
        ]);

        DB::table('call_sessions')->insert([
            [
                'incident_id' => $incidentId,
                'caller_id' => $caller->id,
                'status' => 'ended',
                'outcome' => 'ended_by_operator',
                'started_at' => now()->subHours(2),
                'answered_at' => now()->subHours(2)->addMinute(),
                'ended_at' => now()->subHours(2)->addMinutes(8),
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2)->addMinutes(8),
            ],
            [
                'incident_id' => $incidentId,
                'caller_id' => $caller->id,
                'status' => 'ended',
                'outcome' => 'ended_by_operator',
                'started_at' => now()->subHour(),
                'answered_at' => now()->subHour()->addMinute(),
                'ended_at' => now()->subHour()->addMinutes(6),
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour()->addMinutes(6),
            ],
        ]);

        return [$command, $incidentId, $resourceTypeId, $incidentTypeId];
    }

    private function createSitrepReport(array $attributes = []): SitrepReport
    {
        return SitrepReport::query()->create(array_merge([
            'sequence_number' => 1,
            'title' => 'Public SITREP',
            'coverage_area' => 'PBB Hotline Coverage Area',
            'period_started_at' => now()->subHours(6),
            'period_ended_at' => now(),
            'generated_at' => now(),
            'published_at' => now(),
            'status' => 'published',
            'visibility' => 'public',
            'alert_level' => 'Normal',
            'summary_json' => ['headline' => 'Situation report generated from Hotline incident records.'],
        ], $attributes));
    }
}
