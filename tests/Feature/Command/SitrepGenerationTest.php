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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitrepGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        Carbon::setTestNow(Carbon::parse('2026-04-29 10:00:00'));
        $hubJsonResponse = Http::response([
                'base_url' => 'https://hub.pbb.ph',
                'hub_id' => 12,
                'relay_hub_id' => '072217029',
                'name' => 'Guadalupe, CEBU CITY, CEBU',
                'deployment' => 'barangay',
                'domain' => 'guadalupe-cebu-cebu.pbb.ph',
                'status' => 'active',
                'country_code' => 'PH',
                'reg_code' => '07',
                'prov_code' => '0722',
                'citymun_code' => '072217',
                'brgy_code' => '072217029',
                'uplinks' => [[
                    'id' => 29,
                    'uplink_hub_id' => 11,
                    'uplink_type' => 'hierarchy',
                    'uplink_domain' => 'cebu-cebu.pbb.ph',
                    'priority' => 1,
                    'is_primary' => true,
                    'hub' => [
                        'id' => 11,
                        'name' => 'CEBU CITY, CEBU',
                        'code' => 'cebu-cebu',
                        'deployment' => 'city',
                        'domain' => 'cebu-cebu.pbb.ph',
                        'status' => 'active',
                    ],
                ]],
                'sources' => [],
                'hydrated_at' => '2026-05-26T09:28:40+00:00',
                'hydrated_from' => 'hq_heartbeat',
                'snapshot_version' => 'hub-12:test',
                'snapshot_hash' => 'test-hash',
        ]);

        Http::fake([
            'https://relay.pbb.ph/hub.json' => $hubJsonResponse,
            'relay.pbb.ph/hub.json' => $hubJsonResponse,
        ]);
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
            ->assertJsonPath('sitrep.summary.gap_cards.0.label', 'People at Risk')
            ->assertJsonPath('sitrep.summary.gap_cards.1.label', 'Access to Help')
            ->assertJsonPath('sitrep.summary.gap_cards.2.label', 'Response Progress')
            ->assertJsonPath('sitrep.summary.accomplishment_cards.0.label', 'People Helped')
            ->assertJsonPath('sitrep.summary.accomplishment_cards.1.label', 'Handled Incidents')
            ->assertJsonPath('sitrep.summary.accomplishment_cards.2.label', 'Teams / Resources Deployed')
            ->assertJsonPath('sitrep.situation.decision_points.0.title', 'Life safety')
            ->assertJsonPath('sitrep.situation.multi_type_incident_count', 1)
            ->assertJsonPath('sitrep.situation.concern_groups.0.concern', 'Flood, Rescue, and Displacement')
            ->assertJsonPath('sitrep.situation.concern_groups.0.open_reports', 1)
            ->assertJsonPath('sitrep.situation.concern_groups.0.current_assignments', 1)
            ->assertJsonPath('sitrep.situation.concern_groups.0.resource_units', 3)
            ->assertJsonPath('sitrep.needs.total_quantity_requested', 3)
            ->assertJsonPath('sitrep.needs.items.0.resource', 'Rescue Boat')
            ->assertJsonPath('sitrep.needs.items.0.category', 'Transport')
            ->assertJsonPath('sitrep.needs.category_groups.0.category', 'Transport')
            ->assertJsonPath('sitrep.population.citizens_assisted', 1)
            ->assertJsonPath('sitrep.population.numeric_total', 5)
            ->assertJsonPath('sitrep.data_quality.missing_citizen_location_count', 0)
            ->assertJsonPath('sitrep.source_snapshot.hotline.app', 'pbb-hotline')
            ->assertJsonPath('sitrep.source_snapshot.hotline.display_version', 'v1-5.6.1')
            ->assertJsonPath('sitrep.source_snapshot.hub_node.available', true)
            ->assertJsonPath('sitrep.source_snapshot.hub_node.snapshot.deployment', 'barangay')
            ->assertJsonPath('sitrep.source_snapshot.hub_node.snapshot.relay_hub_id', '072217029')
            ->assertJsonPath('sitrep.source_snapshot.incident_coordinates.0.id', $incidentId)
            ->assertJsonPath('sitrep.source_snapshot.incident_coordinates.0.lat', 10.33049)
            ->assertJsonPath('sitrep.source_snapshot.incident_coordinates.0.lng', 123.88257)
            ->assertJsonPath('sitrep.privacy_redactions.citizen_phone_numbers', 'redacted')
            ->assertJsonMissingPath('sitrep.population.callers_assisted')
            ->assertJsonMissingPath('sitrep.data_quality.missing_caller_location_count')
            ->assertJsonMissingPath('sitrep.privacy_redactions.caller_phone_numbers');

        $this->assertDatabaseHas('sitrep_reports', [
            'title' => 'Cebu Flooding SITREP',
            'prepared_by_user_id' => $command->id,
            'status' => 'draft',
            'visibility' => 'private',
        ]);

        $report = DB::table('sitrep_reports')->first();
        $sourceSnapshot = json_decode($report->source_snapshot_json, true);
        $sourceSnapshot = $sourceSnapshot['rollup'] ?? $sourceSnapshot;

        $this->assertSame([$incidentId], $sourceSnapshot['incident_ids']);
        $this->assertSame([[
            'id' => $incidentId,
            'lat' => 10.33049,
            'lng' => 123.88257,
        ]], $sourceSnapshot['incident_coordinates']);
        $this->assertSame('pbb-hotline', $sourceSnapshot['hotline']['app']);
        $this->assertSame('v1-5.6.1', $sourceSnapshot['hotline']['display_version']);
        $this->assertSame('Guadalupe, CEBU CITY, CEBU', $sourceSnapshot['hub_node']['snapshot']['name']);
        $this->assertSame($command->name, $sourceSnapshot['generation']['prepared_by_label']);
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
            ->assertSee('Executive Situation Assessment')
            ->assertSee('Source Snapshot')
            ->assertSee('Hotline: v1-5.6.1')
            ->assertSee('Hub Node: Guadalupe, Cebu City, Cebu')
            ->assertSee('Citizens assisted')
            ->assertDontSee('Callers assisted')
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

    public function test_sitrep_current_picture_excludes_resolved_and_discarded_demand(): void
    {
        [$command, $activeIncidentId, $resourceTypeId, $incidentTypeId] = $this->seedSitrepScenario();
        $operatorId = DB::table('users')->where('role', UserRole::Operator->value)->value('id');

        $resolvedIncidentId = DB::table('incidents')->insertGetId([
            'operator_id' => $operatorId,
            'status' => IncidentStatus::Resolved->value,
            'alert_level' => 'Critical',
            'latitude' => 10.31,
            'longitude' => 123.89,
            'location_barangay' => 'Labangon',
            'location_citymunicipality' => 'Cebu City',
            'called_at' => now()->subHours(3),
            'resolved_at' => now()->subHour(),
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHour(),
        ]);

        $discardedIncidentId = DB::table('incidents')->insertGetId([
            'operator_id' => $operatorId,
            'status' => IncidentStatus::Discarded->value,
            'alert_level' => 'Normal',
            'latitude' => 10.32,
            'longitude' => 123.90,
            'location_barangay' => 'Capitol Site',
            'location_citymunicipality' => 'Cebu City',
            'called_at' => now()->subHours(2),
            'resolved_at' => now()->subMinutes(30),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subMinutes(30),
        ]);

        $resolvedFamilyFieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'affected_families',
            'field_label' => 'Affected families',
            'input_type' => 'family',
            'unit' => null,
            'is_required' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_details')->insert([
            'incident_id' => $resolvedIncidentId,
            'incident_type_id' => $incidentTypeId,
            'field_id' => $resolvedFamilyFieldId,
            'field_key' => 'affected_families',
            'field_label' => 'Affected families',
            'field_value' => json_encode([[
                'families' => 2,
                'individuals' => 6,
                'children' => 3,
                'senior_citizens' => 1,
                'pregnant' => 1,
                'persons_with_disability' => 1,
                'returned_home' => true,
            ]]),
            'input_type' => 'family',
            'unit' => null,
            'is_required' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_resources_needed')->insert([
            [
                'incident_id' => $resolvedIncidentId,
                'incident_type_id' => $incidentTypeId,
                'resource_type_id' => $resourceTypeId,
                'quantity_required' => 40,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'incident_id' => $discardedIncidentId,
                'incident_type_id' => $incidentTypeId,
                'resource_type_id' => $resourceTypeId,
                'quantity_required' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('team_assignments')->insert([
            [
                'incident_id' => $resolvedIncidentId,
                'team_id' => DB::table('teams')->value('id'),
                'assigned_by_operator_id' => $operatorId,
                'status' => 'accepted',
                'assigned_at' => now()->subHours(2),
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'incident_id' => $discardedIncidentId,
                'team_id' => DB::table('teams')->value('id'),
                'assigned_by_operator_id' => $operatorId,
                'status' => 'accepted',
                'assigned_at' => now()->subHours(2),
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
        ]);

        $response = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Current Picture SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('sitrep.summary.supporting_metrics.resource_need_units', 3)
            ->assertJsonPath('sitrep.summary.supporting_metrics.team_assignments', 1)
            ->assertJsonPath('sitrep.summary.supporting_metrics.active_at_close', 1)
            ->assertJsonPath('sitrep.summary.supporting_metrics.closed_this_period', 1)
            ->assertJsonPath('sitrep.summary.supporting_metrics.discarded_excluded', 1)
            ->assertJsonPath('sitrep.summary.resolved_progress.visible', true)
            ->assertJsonPath('sitrep.summary.resolved_progress.title', 'People Helped and Accomplishments')
            ->assertJsonPath('sitrep.summary.resolved_progress.value', '1 resolved report')
            ->assertJsonPath('sitrep.summary.resolved_progress.highlight_value', '6 people helped')
            ->assertJsonPath('sitrep.summary.accomplishment_cards.0.value', '6 people helped')
            ->assertJsonPath('sitrep.needs.total_quantity_requested', 3)
            ->assertJsonPath('sitrep.actions.deployment_groups.0.category', 'Rescue Teams')
            ->assertJsonPath('sitrep.actions.deployment_groups.0.team', 'Team Alpha')
            ->assertJsonPath('sitrep.actions.deployment_groups.0.status_counts.assigned', 1)
            ->assertJsonPath('sitrep.actions.deployment_groups.0.status_counts.completed', 0)
            ->assertJsonPath('sitrep.actions.deployment_groups.0.status_counts.cancelled', 0)
            ->assertJsonPath('sitrep.actions.deployment_groups.0.total_assignments', 1)
            ->assertJsonPath('sitrep.actions.deployment_groups.0.reports_covered', 1)
            ->assertJsonPath('sitrep.actions.timing_rows.0.current_status', 'Assigned')
            ->assertJsonPath('sitrep.actions.timing_rows.0.assigned_to_accepted', '')
            ->assertJsonPath('sitrep.situation.current_operating_picture.current_resource_units', 3)
            ->assertJsonPath('sitrep.situation.current_operating_picture.current_assignments', 1)
            ->assertJsonPath('sitrep.situation.period_activity.resolved_during_period', 1)
            ->assertJsonPath('sitrep.situation.period_activity.discarded_excluded', 1);

        $this->assertStringContainsString('2 families / 6 people addressed', $response->json('sitrep.summary.resolved_progress.note'));
        $this->assertStringContainsString('3 children, 1 senior, 1 pregnant, 1 PWD declared in resolved family records', $response->json('sitrep.summary.resolved_progress.note'));
        $this->assertNotSame('', $response->json('sitrep.actions.timing_rows.0.elapsed_time'));
        $this->assertSame($activeIncidentId, $response->json('sitrep.source_snapshot.incident_ids.0'));
    }

    public function test_sitrep_formats_structured_detail_rows_for_executive_review(): void
    {
        [$command, $activeIncidentId, , $incidentTypeId] = $this->seedSitrepScenario();

        $shelterFieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'shelter_damage_details',
            'field_label' => 'Shelter damage details',
            'input_type' => 'repeater',
            'unit' => null,
            'is_required' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $familyFieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'affected_families',
            'field_label' => 'Affected families',
            'input_type' => 'repeater',
            'unit' => null,
            'is_required' => false,
            'sort_order' => 11,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roadFieldId = DB::table('incident_type_fields')->insertGetId([
            'incident_type_id' => $incidentTypeId,
            'field_key' => 'road_access_status',
            'field_label' => 'Road access status',
            'input_type' => 'repeater',
            'unit' => null,
            'is_required' => false,
            'sort_order' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('incident_type_details')->insert([
            [
                'incident_id' => $activeIncidentId,
                'incident_type_id' => $incidentTypeId,
                'field_id' => $shelterFieldId,
                'field_key' => 'shelter_damage_details',
                'field_label' => 'Shelter damage details',
                'field_value' => json_encode([[
                    'damage_level' => 'Major',
                    'structure_type' => 'House',
                    'families_affected' => 2,
                    'persons_affected' => 10,
                    'habitable' => false,
                ]]),
                'input_type' => 'repeater',
                'unit' => null,
                'is_required' => false,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'incident_id' => $activeIncidentId,
                'incident_type_id' => $incidentTypeId,
                'field_id' => $familyFieldId,
                'field_key' => 'affected_families',
                'field_label' => 'Affected families',
                'field_value' => json_encode([[
                    'member_count' => 6,
                    'children_count' => 3,
                    'senior_count' => 1,
                    'pwd_count' => 1,
                    'displaced' => true,
                ]]),
                'input_type' => 'repeater',
                'unit' => null,
                'is_required' => false,
                'sort_order' => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'incident_id' => $activeIncidentId,
                'incident_type_id' => $incidentTypeId,
                'field_id' => $roadFieldId,
                'field_key' => 'road_access_status',
                'field_label' => 'Road access status',
                'field_value' => json_encode([[
                    'route_location' => 'Riverside bridge approach',
                    'status' => 'Blocked',
                    'obstruction_type' => 'Floodwater',
                    'cleared' => false,
                ]]),
                'input_type' => 'repeater',
                'unit' => null,
                'is_required' => false,
                'sort_order' => 12,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Readable Detail SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('sitrep.damage.items.1.label', 'Shelter damage')
            ->assertJsonPath('sitrep.population.record_count', 2)
            ->assertJsonPath('sitrep.population.items.1.label', 'Affected family')
            ->assertJsonPath('sitrep.gaps.title', 'Response Constraints and Confidence Gaps');

        $affectedFamilyGroup = collect($response->json('sitrep.population.population_groups'))
            ->firstWhere('population_signal', 'Affected family');

        $this->assertSame('1 displacement signal', $affectedFamilyGroup['notes']);
        $this->assertSame([
            ['breakdown' => 'Children', 'count' => 3],
            ['breakdown' => 'Senior citizens', 'count' => 1],
            ['breakdown' => 'PWD', 'count' => 1],
        ], $affectedFamilyGroup['breakdowns']);
        $this->assertStringContainsString('Major damage', $response->json('sitrep.damage.items.1.value'));
        $this->assertStringContainsString('10 persons affected', $response->json('sitrep.damage.items.1.value'));
        $this->assertStringContainsString('6 persons', $response->json('sitrep.population.items.1.value'));
        $this->assertStringContainsString('2 vulnerable', $response->json('sitrep.population.items.1.value'));

        $roadGap = collect($response->json('sitrep.gaps.items'))
            ->firstWhere('type', 'road_access');

        $this->assertNotNull($roadGap);
        $this->assertSame('Operational constraint', $roadGap['category']);
        $this->assertStringContainsString('Route constraints can affect response timing', $roadGap['decision_relevance']);
        $this->assertStringContainsString('Riverside bridge approach', $roadGap['items'][0]['route_location']);
        $this->assertStringNotContainsString('[', $response->json('sitrep.damage.items.1.value'));
        $this->assertStringNotContainsString('[', $response->json('sitrep.population.items.1.value'));

        $populationGap = collect($response->json('sitrep.gaps.items'))
            ->firstWhere('type', 'population_verification');

        $this->assertNotNull($populationGap);
        $this->assertStringContainsString('Population fields should guide life-safety awareness', $populationGap['decision_relevance']);

        $resourceGap = collect($response->json('sitrep.gaps.items'))
            ->firstWhere('type', 'open_needs');

        $this->assertNotNull($resourceGap);
        $this->assertStringContainsString('Category detail is shown in Current Resource Posture', $resourceGap['evidence']);
        $this->assertStringNotContainsString('Transport: 3', $resourceGap['evidence']);
        $this->assertSame('Transport', $resourceGap['resource_categories'][0]['category']);
        $this->assertSame(3, $resourceGap['resource_categories'][0]['quantity_requested']);
    }

    public function test_sitrep_consumes_group_field_presets_and_tolerates_legacy_values(): void
    {
        [$command, $activeIncidentId, , $incidentTypeId] = $this->seedSitrepScenario();

        $addGroupDetail = function (string $fieldKey, string $fieldLabel, string $preset, mixed $value, int $sortOrder) use ($activeIncidentId, $incidentTypeId): void {
            $fieldId = DB::table('incident_type_fields')->insertGetId([
                'incident_type_id' => $incidentTypeId,
                'field_key' => $fieldKey,
                'field_label' => $fieldLabel,
                'input_type' => 'group',
                'config_json' => json_encode(['preset' => $preset]),
                'unit' => null,
                'is_required' => false,
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('incident_type_details')->insert([
                'incident_id' => $activeIncidentId,
                'incident_type_id' => $incidentTypeId,
                'field_id' => $fieldId,
                'field_key' => $fieldKey,
                'field_label' => $fieldLabel,
                'field_value' => is_string($value) ? $value : json_encode($value),
                'input_type' => 'group',
                'config_json' => json_encode(['preset' => $preset]),
                'unit' => null,
                'is_required' => false,
                'sort_order' => $sortOrder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        $addGroupDetail('patient_details', 'Patient Details', 'casualtyPatient', [[
            'name' => 'Unknown rider',
            'age' => '34',
            'condition' => 'Conscious and stable',
            'triage' => 'minor',
        ]], 20);
        $addGroupDetail('affected_families', 'Affected Families', 'family', [
            'families' => 2,
            'individuals' => 7,
            'children' => 3,
            'senior_citizens' => 1,
            'pregnant' => 1,
            'persons_with_disability' => 1,
            'temporary_shelter_needed' => true,
        ], 21);
        $addGroupDetail('missing_person', 'Missing Person Details', 'person', [
            'name' => 'Unknown senior resident',
            'age' => '70',
            'last_seen_location' => 'Guadalupe chapel',
            'condition' => 'Missing after evacuation',
        ], 22);
        $addGroupDetail('vehicles_involved', 'Vehicles Involved', 'vehicleInvolved', [[
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'Unknown',
            'damage' => 'Broken side mirror',
        ]], 23);
        $addGroupDetail('road_access', 'Road / Access Status', 'roadAccessStatus', [
            'status' => 'partially_blocked',
            'description' => 'Interior path narrowed by mud',
        ], 24);
        $addGroupDetail('infrastructure_damage_details', 'Infrastructure Damage Details', 'infrastructureDamage', [
            'asset_type' => 'Drainage cover',
            'damage' => 'Broken concrete cover with exposed opening',
            'severity' => 'moderate',
            'public_safety_risk' => 'high for pedestrians',
        ], 25);
        $addGroupDetail('shelter_damage', 'Shelter Damage Details', 'shelterDamage', [
            'damaged_structures' => 2,
            'damage_severity' => 'minor_to_moderate',
            'habitable' => 'needs_assessment',
        ], 26);
        $addGroupDetail('legacy_road_access', 'Road / Access Status', 'roadAccessStatus', 'Road passable with caution', 27);
        $addGroupDetail('blank_family', 'Affected Families', 'family', '', 28);

        $response = $this->actingAs($command)->postJson('/api/command/sitreps', [
            'title' => 'Group Preset SITREP',
            'coverage_area' => 'Cebu City',
            'period_started_at' => now()->subHours(6)->toIso8601String(),
            'period_ended_at' => now()->toIso8601String(),
        ]);

        $response->assertCreated();

        $populationItems = collect($response->json('sitrep.population.items'));
        $damageItems = collect($response->json('sitrep.damage.items'));
        $roadGap = collect($response->json('sitrep.gaps.items'))->firstWhere('type', 'road_access');

        $this->assertTrue($populationItems->contains(fn (array $item): bool => $item['label'] === 'Patient or injured person' && str_contains($item['value'], 'Conscious and stable')));
        $this->assertTrue($populationItems->contains(fn (array $item): bool => $item['label'] === 'Affected family' && str_contains($item['value'], '7 persons')));
        $this->assertTrue($populationItems->contains(fn (array $item): bool => $item['label'] === 'Missing person' && str_contains($item['value'], 'Guadalupe chapel')));
        $this->assertTrue($damageItems->contains(fn (array $item): bool => $item['label'] === 'Vehicle involved' && str_contains($item['value'], 'Motorcycle')));
        $this->assertTrue($damageItems->contains(fn (array $item): bool => $item['label'] === 'Infrastructure damage' && str_contains($item['value'], 'Drainage cover')));
        $this->assertTrue($damageItems->contains(fn (array $item): bool => $item['label'] === 'Shelter damage' && str_contains($item['value'], '2 damaged structures')));
        $this->assertNotNull($roadGap);
        $this->assertStringContainsString('Interior path narrowed by mud', $roadGap['items'][0]['route_location']);
        $this->assertStringNotContainsString('{', $populationItems->pluck('value')->implode(' '));
        $this->assertStringNotContainsString('[{', $damageItems->pluck('value')->implode(' '));
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
            ->assertSee('Command Shared SITREP')
            ->assertSee($command->name)
            ->assertDontSee('Command User');

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
            'citizen_id' => $caller->id,
            'actual_citizen_name' => 'Maria Santos',
            'actual_citizen_relationship' => 'self',
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
                'citizen_id' => $caller->id,
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
                'citizen_id' => $caller->id,
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
            'source_snapshot_json' => [
                'hotline' => [
                    'display_version' => 'v1-5.6.1',
                    'version' => '1-5.6.1',
                    'build' => ['id' => 'source-template'],
                ],
            ],
        ], $attributes));
    }
}
