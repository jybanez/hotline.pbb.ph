<?php

namespace App\Support\Bootstrap;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Sitreps\Models\SitrepReport;
use App\Domain\Users\Models\User;
use App\Support\Caller\CallerHomePayloadBuilder;
use App\Support\Incidents\IncidentPayloadBuilder;
use App\Support\Settings\SettingsService;
use Illuminate\Support\Arr;

class BootstrapPayloadBuilder
{
    private const CALLER_RELATIONSHIP_OPTIONS = [
        'Self',
        'Parent',
        'Child',
        'Sibling',
        'Spouse',
        'Partner',
        'Relative',
        'Friend',
        'Neighbor',
        'Coworker',
        'Classmate',
        'Caregiver',
        'Guardian',
        'Employer',
        'Employee',
        'Teacher',
        'Student',
        'Other',
    ];

    public function __construct(
        private readonly CallerHomePayloadBuilder $callerHomePayloadBuilder,
        private readonly IncidentPayloadBuilder $incidentPayloadBuilder,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?User $user, ?string $surface = null): array
    {
        $alertLevel = $this->settings->currentAlertLevel();
        $settings = [
            'alert_level' => $alertLevel->value,
            'call_hold_seconds' => (int) $this->settings->get('call_hold_seconds'),
            'call_timeout_seconds' => (int) $this->settings->get('call_timeout_seconds'),
            'reconnect_timeout_seconds' => (int) $this->settings->get('reconnect_timeout_seconds'),
            'audio_graph_style' => (string) $this->settings->get('audio_graph_style'),
        ];

        return [
            'authenticated' => $user !== null,
            'user' => $user,
            'surface' => $surface,
            'alert_level' => $alertLevel->value,
            'alert_level_description' => $alertLevel->description(),
            'settings' => Arr::where($settings, static fn ($value) => $value !== null),
            'surface_payload' => $this->surfacePayload($user, $surface),
            'lookups' => [
                'caller_relationships' => self::CALLER_RELATIONSHIP_OPTIONS,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function surfacePayload(?User $user, ?string $surface = null): ?array
    {
        if ($surface === 'public') {
            return [
                'sitrep' => $this->publicSitrepPayload(),
            ];
        }

        if (in_array($surface, ['citizen', 'caller'], true) && $user?->role?->isCitizen()) {
            return $this->callerHomePayloadBuilder->build($user);
        }

        if ($surface === 'operator' && $user?->role === UserRole::Operator) {
            return $this->incidentPayloadBuilder->buildWorkbenchLookups();
        }

        return null;
    }

    /**
     * @return array{latest: array<string, mixed>|null, archive: list<array<string, mixed>>}
     */
    private function publicSitrepPayload(): array
    {
        $reports = SitrepReport::query()
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return [
            'latest' => $reports->first() ? $this->serializePublicSitrep($reports->first()) : null,
            'latest_html' => $reports->first() ? $this->renderPublicSitrepDocument($reports->first()) : null,
            'archive' => $reports->skip(1)
                ->map(fn (SitrepReport $report): array => $this->serializePublicSitrep($report))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePublicSitrep(SitrepReport $report): array
    {
        $summary = $report->summary_json ?? [];

        return [
            'id' => $report->id,
            'sequence_number' => $report->sequence_number,
            'report_number' => '#'.str_pad((string) $report->sequence_number, 4, '0', STR_PAD_LEFT),
            'title' => $report->title,
            'coverage_area' => $report->coverage_area,
            'period_started_at' => $report->period_started_at?->toIso8601String(),
            'period_ended_at' => $report->period_ended_at?->toIso8601String(),
            'generated_at' => $report->generated_at?->toIso8601String(),
            'alert_level' => $report->alert_level,
            'headline' => $summary['headline'] ?? null,
            'public_url' => route('sitrep.public.show', ['sitrep' => $report]),
        ];
    }

    private function renderPublicSitrepDocument(SitrepReport $report): string
    {
        return view('pages.sitrep.partials.document', [
            'sitrep' => $report,
            'isPreview' => false,
        ])->render();
    }
}
