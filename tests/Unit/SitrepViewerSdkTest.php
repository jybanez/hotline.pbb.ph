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
            'evidence' => 'Access reports include blocked routes.',
            'confidence_note' => 'Field verification is still required.',
            'items' => [[
                'status' => 'Blocked',
                'route_location' => 'M. Velez Street',
                'obstruction_type' => 'Flooding',
                'cleared' => 'No',
            ]],
        ]];

        $html = $viewer->render($payload);

        $this->assertStringContainsString('sitrep-gap-evidence', $html);
        $this->assertStringContainsString('M. Velez Street', $html);
        $this->assertStringContainsString('Flooding', $html);
        $this->assertStringContainsString('Cleared: No', $html);
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
        $this->assertStringContainsString('<span class="sitrep-gap-route">Riverside bridge approach</span>', $html);
        $this->assertStringContainsString('<span class="sitrep-gap-obstruction">&mdash; Floodwater</span>', $html);
        $this->assertStringContainsString('<span class="sitrep-gap-cleared">Cleared: No</span>', $html);
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
        $this->assertStringContainsString('Source Snapshot', $html);
        $this->assertStringNotContainsString('rollup', $html);
        $this->assertStringNotContainsString('items', $html);
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
                    ['area' => 'Guadalupe', 'count' => 18],
                ],
                'incident_types' => [
                    ['type' => 'Rescue', 'count' => 8],
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
