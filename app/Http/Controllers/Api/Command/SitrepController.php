<?php

namespace App\Http\Controllers\Api\Command;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Http\Controllers\Controller;
use App\Support\Sitreps\SitrepGenerationService;
use App\Support\Sitreps\SitrepPayloadSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SitrepController extends Controller
{
    public function __construct(
        private readonly SitrepGenerationService $sitreps,
    ) {
    }

    public function index(): JsonResponse
    {
        $reports = SitrepReport::query()
            ->latest('generated_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'items' => $reports->map(fn (SitrepReport $report): array => $this->serializeListItem($report))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'coverage_area' => ['nullable', 'string', 'max:255'],
            'period_started_at' => ['required', 'date'],
            'period_ended_at' => ['required', 'date', 'after:period_started_at'],
            'status' => ['nullable', 'string', 'in:draft,published'],
            'visibility' => ['nullable', 'string', 'in:private,public'],
            'publish' => ['nullable', 'boolean'],
        ]);

        $report = $this->sitreps->generate($request->user(), $validated);

        return response()->json([
            'ok' => true,
            'sitrep' => $this->serialize($report),
        ], 201);
    }

    public function show(SitrepReport $sitrep): JsonResponse
    {
        return response()->json([
            'sitrep' => $this->serialize($sitrep),
        ]);
    }

    public function update(Request $request, SitrepReport $sitrep): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:published'],
            'visibility' => ['sometimes', 'string', 'in:private,public'],
        ]);

        if (($validated['visibility'] ?? null) === 'public' && $sitrep->status !== 'published' && ($validated['status'] ?? null) !== 'published') {
            return response()->json([
                'message' => 'Draft SITREPs must remain private. Publish the SITREP before setting it public.',
                'errors' => [
                    'visibility' => ['Draft SITREPs must remain private.'],
                ],
            ], 422);
        }

        if (($validated['status'] ?? null) === 'published' && $sitrep->status === 'draft') {
            $sitrep->status = 'published';
            $sitrep->published_at ??= now();
            $sitrep->reviewed_by_user_id ??= $request->user()?->id;
        }

        if (array_key_exists('visibility', $validated)) {
            $sitrep->visibility = $validated['visibility'];
        }

        if ($sitrep->status === 'draft') {
            $sitrep->visibility = 'private';
        }

        $sitrep->save();

        return response()->json([
            'ok' => true,
            'sitrep' => $this->serialize($sitrep->refresh()),
        ]);
    }

    private function serializeListItem(SitrepReport $report): array
    {
        return [
            'id' => $report->id,
            'sequence_number' => $report->sequence_number,
            'title' => $report->title,
            'coverage_area' => $report->coverage_area,
            'period_started_at' => $report->period_started_at?->toIso8601String(),
            'period_ended_at' => $report->period_ended_at?->toIso8601String(),
            'generated_at' => $report->generated_at?->toIso8601String(),
            'published_at' => $report->published_at?->toIso8601String(),
            'status' => $report->status,
            'visibility' => $report->visibility,
            'alert_level' => $report->alert_level,
            'schema_version' => SitrepPayloadSchema::VERSION,
            'location_count' => count($report->summary_json['items'] ?? []) ?: 1,
            'summary' => SitrepPayloadSchema::rollup($report->summary_json ?? []),
            'source_snapshot' => SitrepPayloadSchema::rollup($report->source_snapshot_json ?? []),
            'public_url' => route('sitrep.public.show', ['sitrep' => $report]),
            'preview_url' => route('sitrep.command.preview', ['sitrep' => $report]),
            'download_urls' => $this->downloadUrls($report),
        ];
    }

    private function serialize(SitrepReport $report): array
    {
        return [
            'id' => $report->id,
            'sequence_number' => $report->sequence_number,
            'title' => $report->title,
            'coverage_area' => $report->coverage_area,
            'period_started_at' => $report->period_started_at?->toIso8601String(),
            'period_ended_at' => $report->period_ended_at?->toIso8601String(),
            'generated_at' => $report->generated_at?->toIso8601String(),
            'published_at' => $report->published_at?->toIso8601String(),
            'status' => $report->status,
            'visibility' => $report->visibility,
            'alert_level' => $report->alert_level,
            'schema_version' => SitrepPayloadSchema::VERSION,
            'location_count' => count($report->summary_json['items'] ?? []) ?: 1,
            'summary' => SitrepPayloadSchema::rollup($report->summary_json ?? []),
            'situation' => SitrepPayloadSchema::rollup($report->situation_json ?? []),
            'damage' => SitrepPayloadSchema::rollup($report->damage_json ?? []),
            'population' => SitrepPayloadSchema::rollup($report->population_json ?? []),
            'actions' => SitrepPayloadSchema::rollup($report->actions_json ?? []),
            'needs' => SitrepPayloadSchema::rollup($report->needs_json ?? []),
            'gaps' => SitrepPayloadSchema::rollup($report->gaps_json ?? []),
            'source_snapshot' => SitrepPayloadSchema::rollup($report->source_snapshot_json ?? []),
            'privacy_redactions' => $report->privacy_redactions_json ?? [],
            'data_quality' => SitrepPayloadSchema::rollup($report->data_quality_json ?? []),
            'public_url' => route('sitrep.public.show', ['sitrep' => $report]),
            'preview_url' => route('sitrep.command.preview', ['sitrep' => $report]),
            'download_urls' => $this->downloadUrls($report),
        ];
    }

    private function downloadUrls(SitrepReport $report): array
    {
        return [
            'pdf' => route('sitrep.command.download', ['sitrep' => $report, 'format' => 'pdf']),
            'json' => route('sitrep.command.download', ['sitrep' => $report, 'format' => 'json']),
            'zip' => route('sitrep.command.download', ['sitrep' => $report, 'format' => 'zip']),
        ];
    }
}
