<?php

namespace App\Support\Media;

use App\Domain\Messages\Models\MessageAttachment;

final class MessageAttachmentMetadata
{
    /**
     * @return list<string>
     */
    public function missingNormalizedImageFields(MessageAttachment $attachment): array
    {
        if (! $this->isImageAttachment($attachment)) {
            return [];
        }

        $missing = [];
        $requirements = [
            'stored_mime_type' => $attachment->stored_mime_type === 'image/webp',
            'mime_type' => $attachment->mime_type === 'image/webp',
            'stored_path' => str_ends_with(strtolower((string) $attachment->stored_path), '.webp'),
            'stored_filename' => trim((string) $attachment->stored_filename) !== '',
            'stored_size_bytes' => (int) ($attachment->stored_size_bytes ?? 0) > 0,
            'file_size' => (int) ($attachment->file_size ?? 0) > 0,
            'image_width' => (int) ($attachment->image_width ?? 0) > 0,
            'image_height' => (int) ($attachment->image_height ?? 0) > 0,
            'sha256' => is_string($attachment->sha256) && preg_match('/^[a-f0-9]{64}$/i', $attachment->sha256) === 1,
            'normalized_at' => $attachment->normalized_at !== null,
        ];

        foreach ($requirements as $field => $passes) {
            if (! $passes) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public function isImageAttachment(MessageAttachment $attachment): bool
    {
        $type = strtolower(trim((string) $attachment->type));
        $mimeType = strtolower(trim((string) $attachment->mime_type));
        $storedMimeType = strtolower(trim((string) $attachment->stored_mime_type));

        return in_array($type, ['image', 'photo'], true)
            || str_starts_with($mimeType, 'image/')
            || str_starts_with($storedMimeType, 'image/');
    }

    public function imageUnavailableReason(MessageAttachment $attachment): ?array
    {
        $missing = $this->missingNormalizedImageFields($attachment);

        if ($missing === []) {
            return null;
        }

        return [
            'available' => false,
            'error' => 'image_attachment_not_normalized',
            'message' => 'Image attachment requires WebP normalization backfill.',
            'missing_metadata' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function responsePayload(MessageAttachment $attachment): array
    {
        $base = [
            'id' => $attachment->id,
            'message_id' => $attachment->message_id,
            'type' => $attachment->type,
            'original_filename' => $attachment->original_filename,
            'stored_path' => $attachment->stored_path,
            'thumbnail_path' => $attachment->thumbnail_path,
            'uploaded_by' => $attachment->uploaded_by,
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];

        if ($unavailable = $this->imageUnavailableReason($attachment)) {
            return [
                ...$base,
                ...$unavailable,
                'mime_type' => null,
                'original_mime_type' => $attachment->original_mime_type,
                'stored_mime_type' => $attachment->stored_mime_type,
                'stored_filename' => $attachment->stored_filename,
                'file_size' => null,
                'stored_size_bytes' => $attachment->stored_size_bytes,
                'image_width' => $attachment->image_width,
                'image_height' => $attachment->image_height,
                'sha256' => $attachment->sha256,
                'normalized_at' => $attachment->normalized_at?->toIso8601String(),
            ];
        }

        return [
            ...$base,
            'available' => true,
            'mime_type' => $attachment->stored_mime_type ?? $attachment->mime_type,
            'original_mime_type' => $attachment->original_mime_type,
            'stored_mime_type' => $attachment->stored_mime_type,
            'stored_filename' => $attachment->stored_filename,
            'file_size' => $attachment->stored_size_bytes ?? $attachment->file_size,
            'stored_size_bytes' => $attachment->stored_size_bytes,
            'image_width' => $attachment->image_width,
            'image_height' => $attachment->image_height,
            'sha256' => $attachment->sha256,
            'normalized_at' => $attachment->normalized_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function mediaRef(MessageAttachment $attachment, array $base = []): array
    {
        if ($unavailable = $this->imageUnavailableReason($attachment)) {
            return array_filter([
                ...$base,
                'kind' => 'message_attachment',
                'attachment_id' => (int) $attachment->id,
                'message_id' => (int) $attachment->message_id,
                'type' => (string) $attachment->type,
                'original_filename' => $this->safeFilename($attachment->original_filename),
                ...$unavailable,
            ], static fn ($value): bool => $value !== null && $value !== []);
        }

        return array_filter([
            ...$base,
            'kind' => 'message_attachment',
            'attachment_id' => (int) $attachment->id,
            'message_id' => (int) $attachment->message_id,
            'type' => (string) $attachment->type,
            'mime_type' => (string) ($attachment->stored_mime_type ?? $attachment->mime_type),
            'original_filename' => $this->safeFilename($attachment->original_filename),
            'stored_mime_type' => $attachment->stored_mime_type,
            'stored_size_bytes' => $attachment->stored_size_bytes !== null ? (int) $attachment->stored_size_bytes : null,
            'image_width' => $attachment->image_width !== null ? (int) $attachment->image_width : null,
            'image_height' => $attachment->image_height !== null ? (int) $attachment->image_height : null,
            'sha256' => $attachment->sha256,
            'normalized_at' => $attachment->normalized_at?->toIso8601String(),
            'created_at' => $attachment->created_at?->toIso8601String(),
        ], static fn ($value): bool => $value !== null && $value !== []);
    }

    private function safeFilename(?string $filename): ?string
    {
        if ($filename === null) {
            return null;
        }

        $basename = basename(str_replace('\\', '/', $filename));

        return $basename !== '' ? $basename : null;
    }
}
