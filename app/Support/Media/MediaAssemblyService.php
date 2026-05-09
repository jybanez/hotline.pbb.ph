<?php

namespace App\Support\Media;

use App\Domain\Calls\Models\CallSession;
use App\Domain\Media\Models\Media;
use App\Domain\Shared\Enums\CallStatus;
use App\Support\Realtime\RealtimeEventPublishService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class MediaAssemblyService
{
    private const WEBM_HEADER_SIGNATURE = "\x1A\x45\xDF\xA3";

    public function __construct(
        private readonly RealtimeEventPublishService $realtimeEvents,
        private readonly MediaBinaryResolver $mediaBinaries,
    ) {
    }

    public function createProcessingAsset(CallSession $callSession, array $payload): Media
    {
        $metadata = [
            ...(Arr::get($payload, 'metadata', []) ?: []),
            'processing' => true,
            'mime_type' => (string) Arr::get($payload, 'mime_type', ''),
            'extension' => (string) Arr::get($payload, 'extension', ''),
            'track_kind' => (string) Arr::get($payload, 'track_kind', ''),
            'segment_key' => (string) Arr::get($payload, 'segment_key', Str::uuid()->toString()),
            'chunk_count' => 0,
            'started_at' => Arr::get($payload, 'started_at'),
            'ended_at' => Arr::get($payload, 'ended_at'),
        ];

        $media = Media::query()->create([
            'incident_id' => $callSession->incident_id,
            'call_session_id' => $callSession->id,
            'type' => (string) $payload['type'],
            'peer_user_id' => Arr::get($payload, 'peer_user_id'),
            'peer_role' => Arr::get($payload, 'peer_role'),
            'peer_label' => Arr::get($payload, 'peer_label'),
            'path' => '',
            'duration_seconds' => Arr::get($payload, 'duration_seconds'),
            'metadata_json' => $metadata,
            'created_at' => now(),
            'available_at' => null,
        ]);

        $this->realtimeEvents->publishIncidentMediaProcessing($media);

        return $media;
    }

    public function storeChunk(Media $media, string $chunkContents, int $chunkIndex): array
    {
        if ($media->available_at !== null) {
            throw new RuntimeException('This media asset is already finalized.');
        }

        $chunkPath = $this->chunkPath($media, $chunkIndex);
        Storage::disk('local')->put($chunkPath, $chunkContents);

        $metadata = $media->metadata_json ?? [];
        $metadata['processing'] = true;
        $metadata['chunk_count'] = max((int) ($metadata['chunk_count'] ?? 0), $chunkIndex + 1);
        $metadata['last_chunk_index'] = $chunkIndex;
        $media->forceFill([
            'metadata_json' => $metadata,
        ])->save();

        return [
            'chunk_path' => $chunkPath,
            'chunk_count' => (int) $metadata['chunk_count'],
        ];
    }

    public function finalizeProcessingAsset(Media $media, array $payload = []): Media
    {
        if ($media->available_at !== null) {
            return $media;
        }

        $metadata = $media->metadata_json ?? [];
        $metadata['ended_at'] = Arr::get($payload, 'ended_at', $metadata['ended_at'] ?? now()->toIso8601String());
        $chunkPaths = $this->chunkPaths($media);

        if ($chunkPaths === []) {
            throw new RuntimeException('No media chunks were uploaded for this asset.');
        }

        $extension = strtolower((string) Arr::get($payload, 'extension', $metadata['extension'] ?? ''));
        $finalPath = $this->finalPathFor($media, $extension !== '' ? $extension : $this->defaultExtensionFor($media->type));
        $this->mergeChunks($chunkPaths, $finalPath);

        $metadata['processing'] = false;
        $metadata['merged_at'] = now()->toIso8601String();
        $metadata['chunks_retained_for_validation'] = true;

        $media->forceFill([
            'path' => $finalPath,
            'duration_seconds' => Arr::get($payload, 'duration_seconds', $media->duration_seconds),
            'metadata_json' => $metadata,
            'available_at' => now(),
        ])->save();

        $media = $media->fresh();
        $this->realtimeEvents->publishIncidentMediaAvailable($media);

        return $media;
    }

    public function registerCompletedAsset(array $payload): Media
    {
        return Media::query()->create([
            'incident_id' => (int) $payload['incident_id'],
            'call_session_id' => (int) $payload['call_session_id'],
            'type' => (string) $payload['type'],
            'peer_user_id' => Arr::get($payload, 'peer_user_id'),
            'peer_role' => Arr::get($payload, 'peer_role'),
            'peer_label' => Arr::get($payload, 'peer_label'),
            'path' => (string) $payload['path'],
            'duration_seconds' => Arr::get($payload, 'duration_seconds'),
            'metadata_json' => Arr::get($payload, 'metadata', []),
            'created_at' => now(),
            'available_at' => now(),
        ]);
    }

    /**
     * @return array{scanned:int,finalized:int,skipped_no_chunks:int,skipped_not_ended:int,failed:int,failed_items:array<int,array<string,mixed>>}
     */
    public function finalizeRecoverableProcessingAssets(int $graceSeconds = 30): array
    {
        $threshold = now()->subSeconds(max(0, $graceSeconds));

        /** @var Collection<int, Media> $assets */
        $assets = Media::query()
            ->with('callSession')
            ->whereNull('available_at')
            ->orderBy('id')
            ->get();

        $summary = [
            'scanned' => $assets->count(),
            'finalized' => 0,
            'skipped_no_chunks' => 0,
            'skipped_not_ended' => 0,
            'failed' => 0,
            'failed_items' => [],
        ];

        foreach ($assets as $media) {
            $callSession = $media->callSession;

            if (
                ! $callSession
                || $callSession->status !== CallStatus::Ended
                || ! $callSession->ended_at
                || $callSession->ended_at->gt($threshold)
            ) {
                $summary['skipped_not_ended']++;
                continue;
            }

            if ($this->chunkPaths($media) === []) {
                $summary['skipped_no_chunks']++;
                continue;
            }

            try {
                $this->finalizeProcessingAsset($media, [
                    'ended_at' => $callSession->ended_at?->toIso8601String(),
                    'duration_seconds' => $media->duration_seconds,
                    'extension' => Arr::get($media->metadata_json ?? [], 'extension'),
                ]);
                $summary['finalized']++;
            } catch (RuntimeException $exception) {
                $summary['failed']++;
                $summary['failed_items'][] = [
                    'media_id' => $media->id,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    private function chunkPaths(Media $media): array
    {
        $base = sprintf('media-processing/%d/%d/%d/chunks', $media->incident_id, $media->call_session_id, $media->id);
        $files = collect(Storage::disk('local')->files($base))
            ->filter(fn (string $path) => str_ends_with($path, '.chunk'))
            ->sort()
            ->values()
            ->all();

        return $files;
    }

    private function chunkPath(Media $media, int $chunkIndex): string
    {
        return sprintf(
            'media-processing/%d/%d/%d/chunks/%06d.chunk',
            $media->incident_id,
            $media->call_session_id,
            $media->id,
            max(0, $chunkIndex)
        );
    }

    private function finalPathFor(Media $media, string $extension): string
    {
        $peerToken = $media->peer_role ?: ($media->peer_user_id ? "user-{$media->peer_user_id}" : 'peer');
        $segmentKey = Str::slug((string) Arr::get($media->metadata_json ?? [], 'segment_key', 'segment'));

        return sprintf(
            'incidents/%d/media/%d/%d_%s_%s.%s',
            $media->incident_id,
            $media->call_session_id,
            $media->id,
            Str::slug($media->type),
            $segmentKey ?: $peerToken,
            $extension
        );
    }

    private function defaultExtensionFor(string $type): string
    {
        return $type === 'caller_video' ? 'webm' : 'weba';
    }

    /**
     * @param  array<int, string>  $chunkPaths
     */
    private function mergeChunks(array $chunkPaths, string $finalPath): void
    {
        $publicDisk = Storage::disk('public');
        $targetPath = $publicDisk->path($finalPath);
        $targetDirectory = dirname($targetPath);
        $outputFormat = $this->outputFormatForPath($finalPath);

        if (! is_dir($targetDirectory) && ! @mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory)) {
            throw new RuntimeException('Unable to prepare media output directory.');
        }

        if ($outputFormat === 'webm') {
            $chunkPaths = $this->normalizedWebmChunkPaths($chunkPaths);

            if (count($chunkPaths) === 1) {
                $contents = Storage::disk('local')->get($chunkPaths[0]);
                $publicDisk->put($finalPath, $contents);
                return;
            }

            $this->mergeWebmStreamChunks($chunkPaths, $targetPath, $outputFormat);
            return;
        }

        if (count($chunkPaths) === 1) {
            $contents = Storage::disk('local')->get($chunkPaths[0]);
            $publicDisk->put($finalPath, $contents);
            return;
        }

        $manifestPath = Storage::disk('local')->path(sprintf(
            'media-processing/manifests/%s.txt',
            Str::uuid()->toString()
        ));

        if (! is_dir(dirname($manifestPath))) {
            mkdir(dirname($manifestPath), 0777, true);
        }

        $manifestLines = array_map(
            fn (string $path) => "file '" . str_replace("'", "'\\''", Storage::disk('local')->path($path)) . "'",
            $chunkPaths
        );
        file_put_contents($manifestPath, implode(PHP_EOL, $manifestLines) . PHP_EOL);

        $process = new Process([
            $this->mediaBinaries->ffmpeg(),
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $manifestPath,
            '-c', 'copy',
            '-f', $outputFormat,
            $targetPath,
        ]);
        $process->run();

        @unlink($manifestPath);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: 'Unable to merge media chunks.'));
        }
    }

    /**
     * @param  array<int, string>  $chunkPaths
     */
    private function mergeWebmStreamChunks(array $chunkPaths, string $targetPath, string $outputFormat): void
    {
        $concatenatedSourcePath = Storage::disk('local')->path(sprintf(
            'media-processing/assembled/%s.webm',
            Str::uuid()->toString()
        ));

        $assembledDirectory = dirname($concatenatedSourcePath);

        if (! is_dir($assembledDirectory) && ! @mkdir($assembledDirectory, 0777, true) && ! is_dir($assembledDirectory)) {
            throw new RuntimeException('Unable to prepare assembled media workspace.');
        }

        $sourceHandle = fopen($concatenatedSourcePath, 'wb');

        if ($sourceHandle === false) {
            throw new RuntimeException('Unable to assemble media chunks for merge.');
        }

        try {
            foreach ($chunkPaths as $chunkPath) {
                $contents = Storage::disk('local')->get($chunkPath);
                fwrite($sourceHandle, $contents);
            }
        } finally {
            fclose($sourceHandle);
        }

        $process = new Process([
            $this->mediaBinaries->ffmpeg(),
            '-y',
            '-i', $concatenatedSourcePath,
            '-c', 'copy',
            '-f', $outputFormat,
            $targetPath,
        ]);
        $process->run();

        @unlink($concatenatedSourcePath);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: 'Unable to merge media chunks.'));
        }
    }

    /**
     * @param  array<int, string>  $chunkPaths
     */
    private function normalizedWebmChunkPaths(array $chunkPaths): array
    {
        $headerIndex = null;

        foreach ($chunkPaths as $index => $chunkPath) {
            $chunk = Storage::disk('local')->get($chunkPath);

            if (str_starts_with($chunk, self::WEBM_HEADER_SIGNATURE)) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            throw new RuntimeException('Incomplete media chunks: no valid WebM header chunk was persisted for this asset.');
        }

        return array_values(array_slice($chunkPaths, $headerIndex));
    }

    private function outputFormatForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'weba', 'webm' => 'webm',
            default => pathinfo($path, PATHINFO_EXTENSION) ?: 'webm',
        };
    }
}
