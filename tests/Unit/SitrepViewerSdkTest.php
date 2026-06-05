<?php

namespace Tests\Unit;

use Pbb\Sitreps\Viewer\SitrepViewer;
use PHPUnit\Framework\TestCase;

class SitrepViewerSdkTest extends TestCase
{
    public function test_renders_full_html_document_from_sitrep_payload(): void
    {
        $viewer = new SitrepViewer();

        $html = $viewer->render($this->sitrep());

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('Barangay SITREP', $html);
        $this->assertStringContainsString('Guadalupe, Cebu City, Cebu', $html);
        $this->assertStringContainsString('People at Risk', $html);
        $this->assertStringContainsString('Source Snapshot', $html);
    }

    public function test_renders_fragment_without_page_shell(): void
    {
        $viewer = new SitrepViewer();

        $html = $viewer->render($this->sitrep(), ['full_document' => false]);

        $this->assertStringStartsWith('<main', $html);
        $this->assertStringNotContainsString('<!doctype html>', $html);
    }

    public function test_renders_individual_official_sections_for_custom_layouts(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();

        $summary = $viewer->renderSection($payload, 'summary');
        $tabs = $viewer->renderSections($payload, ['population', 'needs']);

        $this->assertContains('summary', $viewer->sectionNames());
        $this->assertStringStartsWith('<section class="sitrep-section sitrep-summary">', $summary);
        $this->assertStringContainsString('Executive Situation Assessment', $summary);
        $this->assertStringContainsString('People at Risk', $summary);
        $this->assertStringNotContainsString('Affected People', $summary);
        $this->assertStringNotContainsString('<main', $summary);
        $this->assertStringContainsString('Affected People', $tabs);
        $this->assertStringContainsString('Current Resource Posture', $tabs);
        $this->assertStringNotContainsString('Executive Situation Assessment', $tabs);
    }

    public function test_rejects_unknown_section_names(): void
    {
        $viewer = new SitrepViewer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SITREP section [unknown]');

        $viewer->renderSection($this->sitrep(), 'unknown');
    }

    public function test_builds_visualization_datasets_for_helper_backed_dashboards(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['source_snapshot']['incident_coordinates'] = [
            ['id' => 101, 'lat' => 10.321234, 'lng' => 123.891234],
        ];

        $data = $viewer->visualizationData($payload);

        $this->assertSame(1, $data['schema_version']);
        $this->assertContains('ui.stat.cards', $data['helper_targets']);
        $this->assertContains('ui.charts', $data['helper_targets']);
        $this->assertContains('ui.map.markers', $data['helper_targets']);
        $this->assertSame('ui.stat.cards', $data['sections']['population']['stat_cards']['component']);
        $this->assertSame('People at Risk', $data['sections']['population']['stat_cards']['items'][0]['label']);
        $this->assertSame(18, $data['sections']['population']['stat_cards']['items'][0]['value']);
        $this->assertSame('population.people-at-risk', $data['sections']['population']['stat_cards']['items'][0]['icon']);
        $this->assertSame('ui.charts', $data['sections']['situation']['incident_types']['component']);
        $this->assertSame('horizontal-bar', $data['sections']['situation']['incident_types']['type']);
        $this->assertSame('Rescue', $data['sections']['situation']['incident_types']['data'][0]['label']);
        $this->assertSame('ui.charts', $data['sections']['needs']['category_demand']['component']);
        $this->assertSame('Transport', $data['sections']['needs']['category_demand']['data'][0]['label']);
        $this->assertSame('ui.map.markers', $data['sections']['map']['incident_markers']['component']);
        $this->assertSame(10.32123, $data['sections']['map']['incident_markers']['items'][0]['lat']);
        $this->assertSame(123.89123, $data['sections']['map']['incident_markers']['items'][0]['lng']);
    }

    public function test_builds_one_visualization_section_and_rejects_unknown_visualization_sections(): void
    {
        $viewer = new SitrepViewer();

        $population = $viewer->visualizationSection($this->sitrep(), 'population');

        $this->assertSame('Affected People', $population['stat_cards']['title']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SITREP visualization section [unknown]');

        $viewer->visualizationSection($this->sitrep(), 'unknown');
    }

    public function test_exposes_payload_schema_reference_by_section(): void
    {
        $viewer = new SitrepViewer();

        $reference = $viewer->schemaReference();
        $html = $viewer->schemaReferenceHtml();

        $this->assertNotEmpty($reference);
        $this->assertSame('Envelope', $reference[0]['name']);
        $this->assertContains('summary', $reference[0]['required']);
        $this->assertContains('rollup', $reference[1]['required']);
        $this->assertContains('items[]', $reference[1]['required']);
        $this->assertStringContainsString('SITREP Payload Reference', $html);
        $this->assertStringContainsString('<code>rollup</code>', $html);
        $this->assertStringContainsString('privacy_redactions', $html);
    }

    public function test_escapes_untrusted_payload_text(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['summary']['headline'] = '<script>alert(1)</script>';

        $html = $viewer->render($payload);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_renders_gap_evidence_details_like_hotline_preview(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['gaps']['items'] = [[
            'category' => 'Access',
            'title' => 'Blocked route',
            'decision_relevance' => 'Response routing may need adjustment.',
            'evidence' => 'Barangay Guadalupe, Cebu City, Cebu: Access reports include blocked routes.',
            'confidence_note' => 'Field verification is still required.',
            'source_hubs' => ['Barangay Guadalupe, Cebu City, Cebu'],
            'items' => [[
                'status' => 'Blocked',
                'route_location' => 'M. Velez Street',
                'obstruction_type' => 'Flooding',
                'cleared' => 'No',
                'source_hub_name' => 'Barangay Guadalupe, Cebu City, Cebu',
            ]],
        ]];
        $payload['source_snapshot']['target'] = ['name' => 'Cebu City, Cebu'];

        $html = $viewer->render($payload);

        $this->assertStringContainsString('<th>Location</th><th>Evidence</th>', $html);
        $this->assertStringContainsString('<td>Guadalupe</td><td>Access reports include blocked routes</td>', $html);
        $this->assertStringContainsString('Route Evidence', $html);
        $this->assertStringContainsString('<th>Location</th><th>Status</th><th>Route</th><th>Obstruction</th><th>Cleared</th>', $html);
        $this->assertStringContainsString('M. Velez Street', $html);
        $this->assertStringContainsString('Flooding', $html);
        $this->assertStringContainsString('<td>No</td>', $html);
        $this->assertStringContainsString('Field verification is still required.', $html);
    }

    public function test_uses_hotline_preview_fallback_copy(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        unset($payload['summary']['headline'], $payload['summary']['gap_cards']);
        $payload['summary']['hotspot_area'] = 'Guadalupe';
        $payload['summary']['hotspot_note'] = 'Most current reports remain in Guadalupe.';

        $html = $viewer->render($payload);

        $this->assertStringContainsString('Situation report generated from Hotline incident records.', $html);
        $this->assertStringContainsString('Most current reports remain in Guadalupe.', $html);
        $this->assertStringContainsString('Time in Status shows how long an open assignment has been in its current status', $html);
        $this->assertStringContainsString('<th>Category</th><th>Quantity</th><th>Resources</th>', $html);
        $this->assertStringContainsString('<th>Resource</th><th>Category</th><th>Quantity</th><th>Incidents</th>', $html);
        $this->assertStringNotContainsString('<th>Resource</th><th>Category</th><th>Locations</th>', $html);
        $this->assertStringNotContainsString('<th>Completed</th>', $html);
        $this->assertStringNotContainsString('<th>Cancelled</th>', $html);
        $this->assertStringNotContainsString('Situation report generated from PBB incident records.', $html);
    }

    public function test_chunks_preview_metadata_totals_gap_evidence_and_source_snapshot(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['sequence_number'] = 54;
        $payload['status'] = 'draft';
        $payload['visibility'] = 'private';
        $payload['generated_at'] = '2026-05-27T09:39:00+08:00';
        $payload['source_snapshot']['hotline']['build']['id'] = 'source-template';
        $payload['source_snapshot']['generation']['prepared_by_label'] = 'System Generated';
        $payload['situation']['current_operating_picture'] = [
            'open_reports' => 23,
            'active_reports' => 19,
            'deferred_reports' => 4,
            'current_assignments' => 27,
            'current_resource_units' => 183,
        ];
        $payload['gaps']['items'] = [[
            'category' => 'Access',
            'title' => 'Blocked routes',
            'decision_relevance' => 'Access remains constrained.',
            'items' => [[
                'status' => 'Blocked',
                'route_location' => 'Riverside bridge approach',
                'obstruction_type' => 'Floodwater',
                'cleared' => 'No',
            ]],
        ]];

        $html = $viewer->render($payload);
        $text = $this->visibleText($html);

        $this->assertStringContainsString('<p class="sitrep-metaline"><span>#0054</span> <span class="sitrep-separator">&middot;</span> <span>Draft / Private</span>', $html);
        $this->assertStringContainsString('<span>System Generated</span>', $html);
        $this->assertStringContainsString('<strong>Current totals:</strong> <span>23 open reports</span> <span class="sitrep-separator">&middot;</span> <span>19 active</span>', $html);
        $this->assertStringContainsString('<th>Location</th><th>Status</th><th>Route</th><th>Obstruction</th><th>Cleared</th>', $html);
        $this->assertStringContainsString('<td>Riverside bridge approach</td><td>Floodwater</td><td>No</td>', $html);
        $this->assertStringContainsString('<span>Hotline: v1-5.6.1</span> <span class="sitrep-separator">&middot;</span> <span>Build source-template</span>', $html);
        $this->assertStringContainsString('<span>Hub Node: Guadalupe, Cebu City, Cebu</span> <span class="sitrep-separator">&middot;</span> <span>Barangay</span> <span class="sitrep-separator">&middot;</span> <span>072217029</span>', $html);

        $this->assertStringNotContainsString('FloodwaterCleared', $text);
        $this->assertStringNotContainsString('source-templateHub Node', $text);
        $this->assertStringNotContainsString('PrivateElevatedSystem', $text);
    }

    public function test_renders_consolidated_sitrep_target_and_source_provenance(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['title'] = 'City SITREP - Cebu City, Cebu';
        $payload['source_snapshot'] = [
            'generation' => [
                'type' => 'consolidated',
                'sdk' => 'pbb-sitrep-consolidator',
                'sdk_version' => '0.1.0',
                'merge_rule_version' => 1,
                'prepared_by_label' => 'System Generated',
            ],
            'target' => [
                'hub_id' => '21',
                'name' => 'Cebu City, Cebu',
                'level' => 'city',
            ],
            'source_sitreps' => [
                ['source_hub_id' => '12', 'source_hub_name' => 'Guadalupe'],
                ['source_hub_id' => '13', 'source_hub_name' => 'Lahug'],
            ],
        ];

        $html = $viewer->render($payload, ['full_document' => false]);

        $this->assertStringContainsString('<h1>City SITREP</h1>', $html);
        $this->assertStringContainsString('Cebu City, Cebu', $html);
        $this->assertStringContainsString('Consolidated by pbb sitrep consolidator', $html);
        $this->assertStringContainsString('SDK 0.1.0', $html);
        $this->assertStringContainsString('Merge rule 1', $html);
        $this->assertStringContainsString('Target: Cebu City, Cebu', $html);
        $this->assertStringContainsString('Sources: 2 accepted SITREPs', $html);
    }

    public function test_summary_source_card_details_use_bullets_and_short_locations(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $location = [
            'id' => '12',
            'name' => 'Barangay Guadalupe, Cebu City, Cebu',
            'deployment' => 'barangay',
            'relay_hub_id' => null,
        ];

        foreach (['summary', 'situation', 'damage', 'population', 'actions', 'needs', 'gaps', 'data_quality'] as $section) {
            $sectionData = $payload[$section] ?? [];
            $payload[$section] = [
                'rollup' => $sectionData,
                'items' => [[
                    'location' => $location,
                    'data' => $sectionData,
                ]],
            ];
        }

        $payload['source_snapshot'] = [
            'rollup' => [
                'target' => ['name' => 'Cebu City, Cebu'],
                'generation' => ['type' => 'consolidated', 'prepared_by_label' => 'System Generated'],
                'hub_node' => [
                    'snapshot' => [
                        'hub_id' => '11',
                        'name' => 'Cebu City, Cebu',
                        'deployment' => 'city',
                    ],
                ],
            ],
            'items' => [],
        ];
        $payload['summary']['rollup']['gap_cards'] = [[
            'label' => 'People at Risk',
            'value' => '20',
            'source_values' => [[
                'source_hub_name' => 'Barangay Guadalupe, Cebu City, Cebu',
                'label' => '20 people',
            ]],
        ]];

        $html = $viewer->render($payload);

        $this->assertStringContainsString('sitrep-card-sources', $html);
        $this->assertStringContainsString('<strong>Guadalupe</strong><span>20 people</span>', $html);
        $this->assertStringNotContainsString('<strong>Barangay Guadalupe, Cebu City, Cebu</strong><span>20 people</span>', $html);
    }

    public function test_renders_v2_rollup_sections_without_exposing_wrapper_labels(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['schema_version'] = 2;
        $payload['location_count'] = 1;
        $location = [
            'id' => '072217029',
            'name' => 'Guadalupe, Cebu City, Cebu',
            'deployment' => 'barangay',
            'relay_hub_id' => '072217029',
        ];

        foreach (['summary', 'situation', 'damage', 'population', 'actions', 'needs', 'gaps', 'source_snapshot', 'data_quality'] as $section) {
            $sectionData = $payload[$section] ?? [];
            $payload[$section] = [
                'rollup' => $sectionData,
                'items' => [[
                    'location' => $location,
                    'data' => $sectionData,
                ]],
            ];
        }

        $html = $viewer->render($payload, ['full_document' => false]);

        $this->assertStringContainsString('Rescue and assistance reports remain concentrated in Guadalupe.', $html);
        $this->assertStringContainsString('Current reports indicate continued life-safety and resource support needs.', $html);
        $this->assertStringContainsString('<span>People at Risk</span><strong>18</strong>', $html);
        $this->assertStringContainsString('<div class="sitrep-metric is-positive"><span>People Helped</span><strong>2</strong></div>', $html);
        $this->assertStringContainsString('<span>Current Records</span><strong>6</strong>', $html);
        $this->assertStringContainsString('<th>Alert Level</th>', $html);
        $this->assertStringContainsString('<th>Locations</th>', $html);
        $this->assertStringContainsString('<td>Elevated</td>', $html);
        $this->assertStringContainsString('<td>6 records</td>', $html);
        $this->assertStringContainsString('Declared Member Breakdown', $html);
        $this->assertStringContainsString('<td>Children</td><td>3</td>', $html);
        $this->assertStringNotContainsString('<th>Breakdown</th><th>Locations</th><th>Count</th>', $html);
        $this->assertStringContainsString('Source Snapshot', $html);
        $this->assertStringNotContainsString('rollup', $html);
        $this->assertStringNotContainsString('items', $html);
    }

    public function test_multilocation_tables_show_location_columns(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['location_count'] = 2;

        $html = $viewer->render($payload, ['full_document' => false]);

        $this->assertStringContainsString('<th>Population Signal</th><th>Locations</th><th>Reports</th><th>People / Families</th><th>Notes</th>', $html);
        $this->assertStringContainsString('<th>Population Signal</th><th>Breakdown</th><th>Locations</th><th>Count</th>', $html);
        $this->assertStringContainsString('<th>Category</th><th>Locations</th><th>Quantity</th><th>Resources</th>', $html);
        $this->assertStringContainsString('<th>Resource</th><th>Category</th><th>Locations</th><th>Quantity</th><th>Incidents</th>', $html);
    }

    public function test_renders_direct_source_identity_from_hub_nodes_array(): void
    {
        $viewer = new SitrepViewer();
        $payload = $this->sitrep();
        $payload['source_snapshot']['hub_nodes'] = [$payload['source_snapshot']['hub_node']];
        unset($payload['source_snapshot']['hub_node']);

        $html = $viewer->render($payload, ['full_document' => false]);

        $this->assertStringContainsString('<h1>Barangay SITREP</h1>', $html);
        $this->assertStringContainsString('Guadalupe, Cebu City, Cebu', $html);
        $this->assertStringContainsString('Hub Node: Guadalupe, Cebu City, Cebu', $html);
    }

    public function test_viewer_css_keeps_preview_header_from_squeezing_title(): void
    {
        $css = (new SitrepViewer())->css();

        $this->assertStringContainsString('grid-template-columns: minmax(18rem, 1fr) minmax(0, 34rem);', $css);
        $this->assertStringContainsString('.sitrep-page.is-preview .sitrep-header h1', $css);
        $this->assertStringContainsString('@media (max-width: 980px)', $css);
        $this->assertStringContainsString('grid-template-columns: 1fr;', $css);
        $this->assertStringContainsString('justify-content: flex-start;', $css);
    }

    /**
     * @return array<string, mixed>
     */
    private function sitrep(): array
    {
        return [
            'sequence_number' => 53,
            'title' => 'PBB Hotline Guadalupe SITREP - 2026-05-30',
            'period_started_at' => '2026-05-30T00:00:00+08:00',
            'period_ended_at' => '2026-05-30T23:59:59+08:00',
            'generated_at' => '2026-05-30T09:15:00+08:00',
            'status' => 'published',
            'visibility' => 'public',
            'alert_level' => 'Elevated',
            'summary' => [
                'headline' => 'Rescue and assistance reports remain concentrated in Guadalupe.',
                'gap_cards' => [
                    [
                        'label' => 'People at Risk',
                        'value' => '18 open reports',
                        'note' => 'Active and deferred reports remain in the current picture.',
                    ],
                ],
            ],
            'situation' => [
                'executive_assessment' => 'Current reports indicate continued life-safety and resource support needs.',
                'current_operating_picture' => [
                    'open_reports' => 18,
                    'active_reports' => 17,
                    'deferred_reports' => 1,
                    'current_assignments' => 23,
                    'current_resource_units' => 183,
                ],
                'locations' => [
                    ['area' => 'Guadalupe', 'alert_level' => 'Elevated', 'count' => 18],
                ],
                'incident_types' => [
                    ['type' => 'Rescue', 'location_count' => 1, 'count' => 8],
                ],
            ],
            'source_snapshot' => [
                'hotline' => [
                    'display_version' => 'v1-5.6.1',
                ],
                'hub_node' => [
                    'available' => true,
                    'snapshot' => [
                        'hub_id' => 12,
                        'relay_hub_id' => '072217029',
                        'deployment' => 'barangay',
                        'name' => 'Guadalupe, CEBU CITY, CEBU',
                        'uplinks' => [
                            [
                                'is_primary' => true,
                                'hub' => [
                                    'name' => 'CEBU CITY, CEBU',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'population' => [
                'people_at_risk' => 18,
                'citizens_assisted' => 2,
                'record_count' => 6,
                'population_groups' => [
                    [
                        'population_signal' => 'People injured',
                        'reports' => 6,
                        'people_or_families' => '6 records',
                        'notes' => 'Details reported; verification required.',
                        'breakdowns' => [
                            ['breakdown' => 'Children', 'count' => 3],
                        ],
                    ],
                ],
            ],
            'needs' => [
                'category_groups' => [
                    [
                        'category' => 'Transport',
                        'location_count' => 1,
                        'quantity_requested' => 2,
                        'resources' => ['Rescue Boat'],
                    ],
                ],
                'items' => [
                    [
                        'resource' => 'Rescue Boat',
                        'category' => 'Transport',
                        'location_count' => 1,
                        'quantity_requested' => 2,
                        'incident_count' => 1,
                    ],
                ],
            ],
            'privacy_redactions' => [
                'citizen_phone_numbers' => 'redacted',
            ],
            'data_quality' => [
                'global_note' => 'Generated from current Hotline data.',
            ],
        ];
    }

    private function visibleText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
