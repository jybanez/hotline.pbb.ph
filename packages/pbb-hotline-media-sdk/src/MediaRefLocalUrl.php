<?php

namespace Pbb\Hotline\Media;

final class MediaRefLocalUrl
{
    /**
     * @param  array<string, mixed>  $ref
     */
    public function path(array $ref, string $prefix = '/media'): ?string
    {
        $sourceHubId = $this->text($ref['source_hub_id'] ?? null);
        $incidentId = $this->positiveInt($ref['incident_id'] ?? null);
        $kind = $this->text($ref['kind'] ?? null);

        if ($sourceHubId === null || $incidentId === null || $kind === null) {
            return null;
        }

        $prefix = '/'.trim($prefix, '/');

        if ($kind === 'incident_media') {
            $mediaType = $this->text($ref['type'] ?? null);
            $mediaId = $this->positiveInt($ref['media_id'] ?? $ref['id'] ?? null);

            if ($mediaType === null || $mediaId === null) {
                return null;
            }

            return implode('/', [
                $prefix,
                $this->segment($sourceHubId),
                (string) $incidentId,
                'incident_media',
                $this->segment($mediaType),
                (string) $mediaId,
            ]);
        }

        if ($kind === 'message_attachment') {
            $messageId = $this->positiveInt($ref['message_id'] ?? null);
            $attachmentId = $this->positiveInt($ref['attachment_id'] ?? $ref['id'] ?? null);

            if ($messageId === null || $attachmentId === null) {
                return null;
            }

            return implode('/', [
                $prefix,
                $this->segment($sourceHubId),
                (string) $incidentId,
                'message_attachment',
                (string) $messageId,
                (string) $attachmentId,
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    public function cacheKey(array $ref): ?string
    {
        $sourceHubId = $this->text($ref['source_hub_id'] ?? null);
        $incidentId = $this->positiveInt($ref['incident_id'] ?? null);
        $kind = $this->text($ref['kind'] ?? null);

        if ($sourceHubId === null || $incidentId === null || $kind === null) {
            return null;
        }

        if ($kind === 'incident_media') {
            $mediaId = $this->positiveInt($ref['media_id'] ?? $ref['id'] ?? null);

            return $mediaId !== null
                ? implode(':', [$sourceHubId, $incidentId, 'incident_media', $mediaId])
                : null;
        }

        if ($kind === 'message_attachment') {
            $messageId = $this->positiveInt($ref['message_id'] ?? null);
            $attachmentId = $this->positiveInt($ref['attachment_id'] ?? $ref['id'] ?? null);

            return $messageId !== null && $attachmentId !== null
                ? implode(':', [$sourceHubId, $incidentId, 'message_attachment', $messageId, $attachmentId])
                : null;
        }

        return null;
    }

    private function segment(string $value): string
    {
        return rawurlencode($value);
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($int) && $int > 0 ? $int : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
