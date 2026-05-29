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
}
