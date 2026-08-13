<?php

namespace App\Support\Fallback;

use App\Domain\Fallback\Models\FallbackIncidentDrop;
use App\Domain\Fallback\Models\FallbackIncidentDropAttachment;
use App\Support\Media\ChatPhotoWebpNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FallbackIncidentDropAttachmentService
{
    public function __construct(
        private readonly ChatPhotoWebpNormalizer $normalizer,
    ) {
    }

    public function storeImage(FallbackIncidentDrop $drop, UploadedFile $file): FallbackIncidentDropAttachment
    {
        $normalized = $this->normalizer->normalize($file);
        $path = sprintf(
            'fallback-incident-drops/%d/attachments/%s',
            (int) $drop->id,
            $normalized->storedFilename,
        );

        if (! Storage::disk('local')->put($path, $normalized->contents)) {
            throw new RuntimeException('Fallback photo could not be stored.');
        }

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Fallback photo storage verification failed.');
        }

        return $drop->attachments()->create([
            'type' => 'image',
            'original_filename' => $this->safeFilename($file->getClientOriginalName()),
            'original_mime_type' => $file->getClientMimeType(),
            'stored_mime_type' => $normalized->storedMimeType,
            'stored_path' => $path,
            'stored_filename' => $normalized->storedFilename,
            'original_size_bytes' => $file->getSize(),
            'stored_size_bytes' => $normalized->storedSizeBytes,
            'image_width' => $normalized->width,
            'image_height' => $normalized->height,
            'sha256' => $normalized->sha256,
            'normalized_at' => now(),
        ]);
    }

    private function safeFilename(?string $name): ?string
    {
        $trimmed = trim((string) $name);

        if ($trimmed === '') {
            return null;
        }

        return preg_replace('/[^\pL\pN._ -]+/u', '', basename($trimmed)) ?: null;
    }
}
