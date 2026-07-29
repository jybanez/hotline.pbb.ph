<?php

namespace App\Console\Commands;

use App\Domain\Messages\Models\MessageAttachment;
use App\Support\Media\ChatPhotoWebpNormalizer;
use App\Support\Media\MessageAttachmentMetadata;
use App\Support\Media\MessageAttachmentThumbnailGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class NormalizeChatImageAttachments extends Command
{
    protected $signature = 'app:normalize-chat-image-attachments
        {--dry-run : Audit image attachments without writing changes}
        {--id=* : Limit to one or more message attachment ids}';

    protected $description = 'Normalize existing chat image message attachments to authoritative WebP files.';

    public function handle(
        ChatPhotoWebpNormalizer $normalizer,
        MessageAttachmentMetadata $metadata,
        MessageAttachmentThumbnailGenerator $thumbnailGenerator,
    ): int {
        $query = MessageAttachment::query()
            ->where(function ($query): void {
                $query
                    ->whereIn('type', ['image', 'photo'])
                    ->orWhere('mime_type', 'like', 'image/%')
                    ->orWhere('stored_mime_type', 'like', 'image/%');
            })
            ->orderBy('id');

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id')), static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $attachments = $query->get();
        if ($attachments->isEmpty()) {
            $this->info('No chat image attachments found.');

            return self::SUCCESS;
        }

        $converted = 0;
        $verified = 0;
        $dryRun = (bool) $this->option('dry-run');

        foreach ($attachments as $attachment) {
            try {
                if ($metadata->missingNormalizedImageFields($attachment) === []) {
                    $this->verifyNormalizedFile($attachment);
                    $verified++;
                    $this->line(sprintf('Verified normalized image attachment #%d.', $attachment->id));

                    continue;
                }

                $this->normalizeAttachment($attachment, $normalizer, $thumbnailGenerator, $dryRun);
                $converted++;
            } catch (RuntimeException $exception) {
                $message = sprintf(
                    'Failed normalizing image attachment #%d (%s): %s',
                    $attachment->id,
                    $attachment->stored_path ?: 'missing stored_path',
                    $exception->getMessage(),
                );

                $this->error($message);
                $this->line($message);

                return self::FAILURE;
            }
        }

        $prefix = $dryRun ? 'Dry run completed' : 'Normalization completed';
        $this->info(sprintf('%s: %d converted, %d already normalized.', $prefix, $converted, $verified));

        return self::SUCCESS;
    }

    private function normalizeAttachment(
        MessageAttachment $attachment,
        ChatPhotoWebpNormalizer $normalizer,
        MessageAttachmentThumbnailGenerator $thumbnailGenerator,
        bool $dryRun,
    ): void {
        $disk = Storage::disk('public');
        $sourcePath = trim((string) $attachment->stored_path);

        if ($sourcePath === '') {
            throw new RuntimeException('stored_path is empty.');
        }

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("source file does not exist on public disk: {$sourcePath}");
        }

        $sourceAbsolutePath = $disk->path($sourcePath);
        $targetPath = $this->targetPath($attachment);
        $targetFilename = basename($targetPath);
        $normalized = $normalizer->normalizePath($sourceAbsolutePath, $targetFilename);

        if ($dryRun) {
            $this->line(sprintf(
                'Would normalize image attachment #%d to %s (%dx%d, %d bytes).',
                $attachment->id,
                $targetPath,
                $normalized->width,
                $normalized->height,
                $normalized->storedSizeBytes,
            ));

            return;
        }

        DB::transaction(function () use ($attachment, $disk, $targetPath, $targetFilename, $normalized, $thumbnailGenerator): void {
            if (! $disk->put($targetPath, $normalized->contents)) {
                throw new RuntimeException("failed writing normalized WebP: {$targetPath}");
            }

            $this->verifyWrittenWebp($targetPath, $normalized->sha256, $normalized->storedSizeBytes, $normalized->width, $normalized->height);

            $thumbnailPath = $thumbnailGenerator->generate($targetPath, 'image');
            if ($thumbnailPath === null || ! $disk->exists($thumbnailPath)) {
                throw new RuntimeException("failed generating thumbnail for normalized WebP: {$targetPath}");
            }

            $attachment->forceFill([
                'original_mime_type' => $attachment->original_mime_type ?: $attachment->mime_type,
                'stored_mime_type' => $normalized->storedMimeType,
                'stored_filename' => $targetFilename,
                'stored_size_bytes' => $normalized->storedSizeBytes,
                'image_width' => $normalized->width,
                'image_height' => $normalized->height,
                'sha256' => $normalized->sha256,
                'normalized_at' => now(),
                'type' => 'image',
                'mime_type' => $normalized->storedMimeType,
                'file_size' => $normalized->storedSizeBytes,
                'stored_path' => $targetPath,
                'thumbnail_path' => $thumbnailPath,
            ])->save();
        });

        $attachment->refresh();
        $this->verifyNormalizedFile($attachment);
        $this->line(sprintf('Normalized image attachment #%d to %s.', $attachment->id, $targetPath));
    }

    private function targetPath(MessageAttachment $attachment): string
    {
        $directory = trim(str_replace('\\', '/', dirname((string) $attachment->stored_path)), './');
        $filename = sprintf('attachment-%06d.webp', $attachment->id);

        return ltrim(($directory !== '' ? $directory.'/' : '').$filename, '/');
    }

    private function verifyNormalizedFile(MessageAttachment $attachment): void
    {
        $missing = (new MessageAttachmentMetadata)->missingNormalizedImageFields($attachment);
        if ($missing !== []) {
            throw new RuntimeException('normalized metadata is incomplete: '.implode(', ', $missing));
        }

        $this->verifyWrittenWebp(
            (string) $attachment->stored_path,
            (string) $attachment->sha256,
            (int) $attachment->stored_size_bytes,
            (int) $attachment->image_width,
            (int) $attachment->image_height,
        );
    }

    private function verifyWrittenWebp(string $storedPath, string $expectedSha256, int $expectedSize, int $expectedWidth, int $expectedHeight): void
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($storedPath)) {
            throw new RuntimeException("normalized file is missing: {$storedPath}");
        }

        $absolutePath = $disk->path($storedPath);
        $bytes = file_get_contents($absolutePath);
        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException("normalized file could not be read: {$storedPath}");
        }

        $actualSize = strlen($bytes);
        if ($actualSize !== $expectedSize) {
            throw new RuntimeException("normalized size mismatch for {$storedPath}: expected {$expectedSize}, got {$actualSize}");
        }

        $actualSha256 = hash('sha256', $bytes);
        if (! hash_equals($expectedSha256, $actualSha256)) {
            throw new RuntimeException("normalized SHA-256 mismatch for {$storedPath}");
        }

        $info = @getimagesize($absolutePath);
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if ($mime !== 'image/webp') {
            throw new RuntimeException("normalized MIME mismatch for {$storedPath}: {$mime}");
        }

        if ((int) ($info[0] ?? 0) !== $expectedWidth || (int) ($info[1] ?? 0) !== $expectedHeight) {
            throw new RuntimeException("normalized dimensions mismatch for {$storedPath}");
        }
    }
}
