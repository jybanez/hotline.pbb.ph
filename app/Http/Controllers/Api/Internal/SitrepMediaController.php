<?php

namespace App\Http\Controllers\Api\Internal;

use App\Domain\Media\Models\Media;
use App\Domain\Messages\Models\MessageAttachment;
use App\Http\Controllers\Controller;
use App\Support\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SitrepMediaController extends Controller
{
    private const KINDS = ['incident_media', 'message_attachment'];

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function manifest(Request $request): JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            $this->logAccess('manifest_unauthorized', $request);

            return response()->json([
                'ok' => false,
                'message' => 'Invalid SITREP media access token.',
            ], 401);
        }

        $validated = $request->validate([
            'media_refs' => ['nullable', 'array'],
            'media_refs.*' => ['array'],
            'refs' => ['nullable', 'array'],
            'refs.*' => ['array'],
        ]);

        $refs = $this->mediaRefs($validated);
        $items = [];
        $unavailable = [];

        foreach ($refs as $index => $ref) {
            $resolved = $this->resolveRef($ref);

            if (($resolved['status'] ?? null) === 'available') {
                $items[] = $resolved['item'];

                continue;
            }

            $unavailable[] = [
                'index' => $index,
                'status' => $resolved['status'] ?? 'unavailable',
                'reason' => $resolved['reason'] ?? 'unavailable',
                'ref' => $this->safeRef($ref),
            ];
        }

        $this->logAccess('manifest', $request, [
            'requested_count' => count($refs),
            'available_count' => count($items),
            'unavailable_count' => count($unavailable),
        ]);

        return response()->json([
            'ok' => true,
            'items' => $items,
            'unavailable' => $unavailable,
            'caller' => $this->callerMetadata($request),
        ]);
    }

    public function download(Request $request, string $kind, int $id): StreamedResponse|JsonResponse
    {
        if (! $this->isAuthorized($request)) {
            $this->logAccess('download_unauthorized', $request, [
                'kind' => $kind,
                'id' => $id,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Invalid SITREP media access token.',
            ], 401);
        }

        if (! in_array($kind, self::KINDS, true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Unsupported SITREP media kind.',
            ], 404);
        }

        $ref = [
            'kind' => $kind,
            $kind === 'message_attachment' ? 'attachment_id' : 'media_id' => $id,
            'incident_id' => $request->query('incident_id'),
            'message_id' => $request->query('message_id'),
        ];
        $resolved = $this->resolveRef($ref, includeDownloadUrl: false);

        if (($resolved['status'] ?? null) !== 'available') {
            $this->logAccess('download_unavailable', $request, [
                'kind' => $kind,
                'id' => $id,
                'reason' => $resolved['reason'] ?? 'unavailable',
            ]);

            return response()->json([
                'ok' => false,
                'message' => $resolved['reason'] ?? 'Media item is unavailable.',
            ], ($resolved['status'] ?? null) === 'context_mismatch' ? 422 : 404);
        }

        $item = $resolved['item'];
        $path = (string) ($item['storage_key'] ?? '');
        $disk = Storage::disk('public');

        if ($path === '' || ! $disk->exists($path)) {
            $this->logAccess('download_missing_file', $request, [
                'kind' => $kind,
                'id' => $id,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Media file is unavailable.',
            ], 404);
        }

        $this->logAccess('download', $request, [
            'kind' => $kind,
            'id' => $id,
            'incident_id' => $item['incident_id'] ?? null,
        ]);

        return $disk->download(
            $path,
            $this->downloadFilename($item),
            array_filter([
                'Content-Type' => $item['mime_type'] ?? null,
                'X-Hotline-Sitrep-Media-Kind' => $kind,
                'X-Hotline-Sitrep-Media-Id' => (string) $id,
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function mediaRefs(array $payload): array
    {
        $refs = $payload['media_refs'] ?? $payload['refs'] ?? [];

        return array_values(array_filter(is_array($refs) ? $refs : [], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function resolveRef(array $ref, bool $includeDownloadUrl = true): array
    {
        $kind = trim((string) ($ref['kind'] ?? ''));

        if (! in_array($kind, self::KINDS, true)) {
            return ['status' => 'rejected', 'reason' => 'unsupported_kind'];
        }

        return $kind === 'message_attachment'
            ? $this->resolveMessageAttachment($ref, $includeDownloadUrl)
            : $this->resolveIncidentMedia($ref, $includeDownloadUrl);
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function resolveIncidentMedia(array $ref, bool $includeDownloadUrl): array
    {
        $mediaId = (int) ($ref['media_id'] ?? $ref['id'] ?? 0);

        if ($mediaId < 1) {
            return ['status' => 'rejected', 'reason' => 'missing_media_id'];
        }

        $media = Media::query()->find($mediaId);

        if (! $media || ! $media->available_at || trim((string) $media->path) === '') {
            return ['status' => 'unavailable', 'reason' => 'incident_media_unavailable'];
        }

        if ($this->hasContextMismatch($ref, 'incident_id', $media->incident_id)) {
            return ['status' => 'context_mismatch', 'reason' => 'incident_context_mismatch'];
        }

        $metadata = is_array($media->metadata_json) ? $media->metadata_json : [];
        $item = [
            'status' => 'available',
            'kind' => 'incident_media',
            'source_hub_id' => $this->text($ref['source_hub_id'] ?? null),
            'incident_ref' => $this->text($ref['incident_ref'] ?? null),
            'evidence_ref' => $this->text($ref['evidence_ref'] ?? null),
            'incident_id' => (int) $media->incident_id,
            'media_id' => (int) $media->id,
            'type' => (string) $media->type,
            'mime_type' => $this->text($metadata['mime_type'] ?? null) ?? $this->mimeType((string) $media->path),
            'original_filename' => $this->safeFilename($metadata['original_filename'] ?? $metadata['filename'] ?? null),
            'file_size' => $this->fileSize((string) $media->path),
            'created_at' => $media->created_at?->toIso8601String(),
            'available_at' => $media->available_at?->toIso8601String(),
            'source_metadata' => array_filter([
                'peer_role' => $this->text($media->peer_role),
                'duration_seconds' => $media->duration_seconds,
            ], static fn ($value) => $value !== null),
            'storage_key' => (string) $media->path,
        ];

        if ($includeDownloadUrl) {
            $item['download_url'] = $this->downloadUrl('incident_media', (int) $media->id, [
                'incident_id' => (int) $media->incident_id,
            ]);
        }

        return ['status' => 'available', 'item' => $includeDownloadUrl ? $this->publicItem($item) : $item];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function resolveMessageAttachment(array $ref, bool $includeDownloadUrl): array
    {
        $attachmentId = (int) ($ref['attachment_id'] ?? $ref['id'] ?? 0);

        if ($attachmentId < 1) {
            return ['status' => 'rejected', 'reason' => 'missing_attachment_id'];
        }

        $attachment = MessageAttachment::query()
            ->with('message')
            ->find($attachmentId);

        if (! $attachment || trim((string) $attachment->stored_path) === '') {
            return ['status' => 'unavailable', 'reason' => 'message_attachment_unavailable'];
        }

        $message = $attachment->message;
        if (! $message) {
            return ['status' => 'unavailable', 'reason' => 'message_attachment_unavailable'];
        }

        if ($this->hasContextMismatch($ref, 'incident_id', $message->incident_id)
            || $this->hasContextMismatch($ref, 'message_id', $attachment->message_id)) {
            return ['status' => 'context_mismatch', 'reason' => 'message_attachment_context_mismatch'];
        }

        $item = [
            'status' => 'available',
            'kind' => 'message_attachment',
            'source_hub_id' => $this->text($ref['source_hub_id'] ?? null),
            'incident_ref' => $this->text($ref['incident_ref'] ?? null),
            'evidence_ref' => $this->text($ref['evidence_ref'] ?? null),
            'incident_id' => (int) $message->incident_id,
            'message_id' => (int) $attachment->message_id,
            'attachment_id' => (int) $attachment->id,
            'type' => (string) $attachment->type,
            'mime_type' => (string) $attachment->mime_type,
            'original_filename' => $this->safeFilename($attachment->original_filename),
            'file_size' => (int) $attachment->file_size,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'source_metadata' => array_filter([
                'uploader_role' => $this->text($message->sender_role),
                'uploaded_by' => $attachment->uploaded_by ? (int) $attachment->uploaded_by : null,
            ], static fn ($value) => $value !== null),
            'storage_key' => (string) $attachment->stored_path,
        ];

        if ($includeDownloadUrl) {
            $item['download_url'] = $this->downloadUrl('message_attachment', (int) $attachment->id, [
                'incident_id' => (int) $message->incident_id,
                'message_id' => (int) $attachment->message_id,
            ]);
        }

        return ['status' => 'available', 'item' => $includeDownloadUrl ? $this->publicItem($item) : $item];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function publicItem(array $item): array
    {
        unset($item['storage_key']);

        return array_filter($item, static fn ($value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function downloadUrl(string $kind, int $id, array $context = []): string
    {
        $query = http_build_query(array_filter($context, static fn ($value): bool => $value !== null && $value !== ''));

        return url(sprintf('/api/internal/sitrep/media/%s/%d', $kind, $id)).($query !== '' ? '?'.$query : '');
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function hasContextMismatch(array $ref, string $key, mixed $actual): bool
    {
        if (! isset($ref[$key]) || $ref[$key] === '' || $ref[$key] === null) {
            return false;
        }

        return (string) $ref[$key] !== (string) $actual;
    }

    private function isAuthorized(Request $request): bool
    {
        $provided = trim((string) (
            $request->header('X-Hotline-Media-Key', '')
            ?: $request->header('X-Hotline-Media-Token', '')
            ?: $request->bearerToken()
        ));
        $expected = trim((string) $this->settings->get('sitrep_media_access_token', ''));

        return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
    }

    /**
     * @return array<string, string|null>
     */
    private function callerMetadata(Request $request): array
    {
        return [
            'source_system' => $this->text($request->header('X-PBB-Source-System')),
            'source_hub_id' => $this->text($request->header('X-PBB-Source-Hub-Id')),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logAccess(string $event, Request $request, array $context = []): void
    {
        Log::info('SITREP media access '.$event, [
            ...$context,
            'source_system' => $request->header('X-PBB-Source-System'),
            'source_hub_id' => $request->header('X-PBB-Source-Hub-Id'),
            'ip' => $request->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function safeRef(array $ref): array
    {
        return array_intersect_key($ref, array_flip([
            'kind',
            'source_hub_id',
            'incident_id',
            'incident_ref',
            'media_id',
            'attachment_id',
            'message_id',
        ]));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function downloadFilename(array $item): string
    {
        $filename = $this->safeFilename($item['original_filename'] ?? null);
        if ($filename !== null) {
            return $filename;
        }

        return sprintf('%s-%s.bin', $item['kind'] ?? 'media', $item['media_id'] ?? $item['attachment_id'] ?? 'item');
    }

    private function fileSize(string $path): ?int
    {
        return Storage::disk('public')->exists($path) ? Storage::disk('public')->size($path) : null;
    }

    private function mimeType(string $path): ?string
    {
        return Storage::disk('public')->exists($path) ? Storage::disk('public')->mimeType($path) : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function safeFilename(mixed $value): ?string
    {
        $filename = trim((string) $value);

        if ($filename === '' || preg_match('/[\/\\\\\x00-\x1F\x7F]/', $filename) === 1) {
            return null;
        }

        return Str::limit($filename, 180, '');
    }
}
