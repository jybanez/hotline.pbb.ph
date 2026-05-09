<?php

namespace App\Http\Controllers\Web;

use App\Domain\Sitreps\Models\SitrepReport;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;
use ZipArchive;

class SitrepPageController extends Controller
{
    public function public(SitrepReport $sitrep): View
    {
        abort_unless($sitrep->isPubliclyVisible(), 404);

        return view('pages.sitrep.show', [
            'sitrep' => $sitrep,
            'isPreview' => false,
        ]);
    }

    public function preview(SitrepReport $sitrep): View
    {
        return view('pages.sitrep.show', [
            'sitrep' => $sitrep,
            'isPreview' => true,
        ]);
    }

    public function download(Request $request, SitrepReport $sitrep, string $format): Response|BinaryFileResponse
    {
        $filename = $this->exportBaseName($sitrep).'.'.$format;

        return match ($format) {
            'json' => response($this->exportJson($sitrep), 200, [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Content-Type' => 'application/json; charset=utf-8',
            ]),
            'zip' => $this->downloadZip($sitrep, $filename),
            'pdf' => $this->downloadPdf($request, $sitrep, $filename),
            default => abort(404),
        };
    }

    private function downloadPdf(Request $request, SitrepReport $sitrep, string $filename): BinaryFileResponse
    {
        $workDir = storage_path('app/sitreps/pdf');
        File::ensureDirectoryExists($workDir);

        $token = bin2hex(random_bytes(8));
        $htmlPath = $workDir.DIRECTORY_SEPARATOR.$this->exportBaseName($sitrep).'-'.$token.'.html';
        $pdfPath = $workDir.DIRECTORY_SEPARATOR.$this->exportBaseName($sitrep).'-'.$token.'.pdf';

        $html = view('pages.sitrep.show', [
            'sitrep' => $sitrep,
            'isPreview' => false,
            'isPdf' => true,
        ])->render();

        File::put($htmlPath, $this->inlineZipHtmlCss($html));

        try {
            $process = new Process([
                env('SITREP_NODE_BINARY', 'node'),
                base_path('bin/render-sitrep-pdf.mjs'),
                $htmlPath,
                $pdfPath,
            ], base_path());
            $process->setEnv([
                'PLAYWRIGHT_BROWSERS_PATH' => env('PLAYWRIGHT_BROWSERS_PATH', storage_path('app/playwright-browsers')),
                'SITREP_PRINT_REPORT' => 'Report #'.str_pad((string) $sitrep->sequence_number, 4, '0', STR_PAD_LEFT),
                'SITREP_PRINTED_AT' => 'Printed '.now()->format('M d, Y g:i A'),
                'SITREP_PRINTED_BY' => 'By '.($request->user()?->name ?? $request->user()?->email ?? 'Unknown user'),
            ]);
            $process->setTimeout(180);
            $process->mustRun();
        } catch (\Throwable $exception) {
            File::delete([$htmlPath, $pdfPath]);

            report($exception);
            abort(500, 'Unable to render SITREP PDF. Confirm Playwright and Chromium are installed on this server.');
        }

        File::delete($htmlPath);

        return response()->download($pdfPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    private function downloadZip(SitrepReport $sitrep, string $filename): BinaryFileResponse
    {
        abort_unless(class_exists(ZipArchive::class), 500, 'ZIP extension is not available.');

        $path = tempnam(sys_get_temp_dir(), 'sitrep-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('sitrep.json', $this->exportJson($sitrep));
        $html = view('pages.sitrep.show', [
            'sitrep' => $sitrep,
            'isPreview' => false,
        ])->render();

        $zip->addFromString('sitrep.html', $this->inlineZipHtmlCss($html));
        $zip->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    private function inlineZipHtmlCss(string $html): string
    {
        $html = preg_replace('/<link[^>]+rel="preload"[^>]+as="style"[^>]+href="[^"]*\/build\/assets\/[^"]+\.css"[^>]*>/i', '', $html) ?? $html;

        return preg_replace_callback('/<link[^>]+rel="stylesheet"[^>]+href="[^"]*\/build\/assets\/([^"]+\.css)"[^>]*>/i', function (array $matches): string {
            $filename = basename($matches[1]);
            $assetPath = public_path('build/assets/'.$filename);

            if (is_file($assetPath)) {
                return '<style data-inlined-asset="'.$filename.'">'."\n".file_get_contents($assetPath)."\n".'</style>';
            }

            return $matches[0];
        }, $html) ?? $html;
    }

    private function exportJson(SitrepReport $sitrep): string
    {
        return json_encode($this->exportPayload($sitrep), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }

    private function exportPayload(SitrepReport $sitrep): array
    {
        return [
            'id' => $sitrep->id,
            'sequence_number' => $sitrep->sequence_number,
            'title' => $sitrep->title,
            'coverage_area' => $sitrep->coverage_area,
            'period_started_at' => $sitrep->period_started_at?->toIso8601String(),
            'period_ended_at' => $sitrep->period_ended_at?->toIso8601String(),
            'generated_at' => $sitrep->generated_at?->toIso8601String(),
            'published_at' => $sitrep->published_at?->toIso8601String(),
            'status' => $sitrep->status,
            'visibility' => $sitrep->visibility,
            'alert_level' => $sitrep->alert_level,
            'summary' => $sitrep->summary_json ?? [],
            'situation' => $sitrep->situation_json ?? [],
            'damage' => $sitrep->damage_json ?? [],
            'population' => $sitrep->population_json ?? [],
            'actions' => $sitrep->actions_json ?? [],
            'needs' => $sitrep->needs_json ?? [],
            'gaps' => $sitrep->gaps_json ?? [],
            'source_snapshot' => $sitrep->source_snapshot_json ?? [],
            'privacy_redactions' => $sitrep->privacy_redactions_json ?? [],
            'data_quality' => $sitrep->data_quality_json ?? [],
        ];
    }

    private function exportBaseName(SitrepReport $sitrep): string
    {
        return 'sitrep-'.str_pad((string) $sitrep->id, 6, '0', STR_PAD_LEFT);
    }

}
