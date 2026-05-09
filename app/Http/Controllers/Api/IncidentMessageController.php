<?php

namespace App\Http\Controllers\Api;

use App\Domain\Incidents\Models\Incident;
use App\Domain\Messages\Models\IncidentMessage;
use App\Domain\Messages\Models\MessageAttachment;
use App\Domain\Shared\Enums\UserRole;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Media\MediaBinaryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class IncidentMessageController extends Controller
{
    public function __construct(
        private readonly MediaBinaryResolver $mediaBinaries,
    ) {
    }

    public function index(Request $request, Incident $incident): JsonResponse
    {
        if (! $this->canAccessIncident($request, $incident)) {
            abort(404);
        }

        return response()->json([
            'items' => $incident->messages()
                ->with(['attachments', 'sender'])
                ->orderBy('created_at')
                ->get()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'incident_id' => $message->incident_id,
                    'sender_id' => $message->sender_id,
                    'sender_role' => $message->sender_role,
                    'sender_name' => $message->sender?->name,
                    'sender_avatar' => $message->sender?->avatar,
                    'body' => $message->body,
                    'type' => $message->type,
                    'attachments' => $message->attachments->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'message_id' => $attachment->message_id,
                        'type' => $attachment->type,
                        'mime_type' => $attachment->mime_type,
                        'original_filename' => $attachment->original_filename,
                        'stored_path' => $attachment->stored_path,
                        'file_size' => $attachment->file_size,
                        'thumbnail_path' => $attachment->thumbnail_path,
                        'uploaded_by' => $attachment->uploaded_by,
                        'created_at' => $attachment->created_at?->toIso8601String(),
                    ])->values()->all(),
                    'created_at' => $message->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request, Incident $incident): JsonResponse
    {
        if (! $this->canAccessIncident($request, $incident)) {
            abort(404);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'sender' => ['nullable', 'array'],
            'sender.id' => ['required_with:sender', 'integer'],
            'sender.role' => ['required_with:sender', 'string', 'in:caller,operator'],
            'sender.name' => ['required_with:sender', 'string', 'max:255'],
            'sender.avatar' => ['nullable', 'string', 'max:2048'],
        ]);

        $user = $request->user();
        $sender = $this->resolveSenderPayload($validated['sender'] ?? null, $incident, $user);

        $payload = [
            'incident_id' => $incident->id,
            'sender_id' => $sender['id'],
            'sender_role' => $sender['role'],
            'body' => trim((string) $validated['body']),
            'type' => 'message',
            'created_at' => now(),
        ];

        if ($this->usesLegacySenderSnapshotColumns()) {
            $senderUser = User::query()->find($sender['id']);
            $payload['sender_name'] = $senderUser?->name ?? '';
            $payload['sender_avatar'] = $senderUser?->avatar;
        }

        $message = IncidentMessage::query()->create($payload)->load(['attachments', 'sender']);

        return response()->json([
            'item' => [
                'id' => $message->id,
                'incident_id' => $message->incident_id,
                'sender_id' => $message->sender_id,
                'sender_role' => $message->sender_role,
                'sender_name' => $message->sender?->name,
                'sender_avatar' => $message->sender?->avatar,
                'body' => $message->body,
                'type' => $message->type,
                'attachments' => [],
                'created_at' => $message->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function storeAttachment(Request $request, Incident $incident, IncidentMessage $message): JsonResponse
    {
        if (! $this->canPersistIncidentArtifacts($request, $incident, $message)) {
            abort(404);
        }

        $validated = $request->validate([
            'attachment' => ['required', 'file', 'max:51200'],
            'type' => ['nullable', 'string', 'in:image,video,audio,file'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['attachment'];
        $type = $this->normalizeAttachmentType($validated['type'] ?? null, $file);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'attachment';
        $filename = sprintf(
            '%s_%s%s',
            now()->format('YmdHis'),
            Str::limit($safeName, 48, ''),
            $extension !== '' ? ".{$extension}" : ''
        );
        $storedPath = $file->storeAs(
            sprintf('incident-messages/%d/%d', $incident->id, $message->id),
            $filename,
            'public',
        );

        $attachment = MessageAttachment::query()->create([
            'message_id' => $message->id,
            'type' => $type,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_size' => $file->getSize() ?: 0,
            'thumbnail_path' => $this->generateAttachmentThumbnail($storedPath, $type),
            'uploaded_by' => (int) $request->user()->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'item' => [
                'id' => $attachment->id,
                'message_id' => $attachment->message_id,
                'type' => $attachment->type,
                'mime_type' => $attachment->mime_type,
                'original_filename' => $attachment->original_filename,
                'stored_path' => $attachment->stored_path,
                'file_size' => $attachment->file_size,
                'thumbnail_path' => $attachment->thumbnail_path,
                'uploaded_by' => $attachment->uploaded_by,
                'created_at' => $attachment->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    private function canAccessIncident(Request $request, Incident $incident): bool
    {
        $user = $request->user();

        return ((int) $incident->caller_id === (int) $user->id)
            || ((int) $incident->operator_id === (int) $user->id);
    }

    private function canPersistIncidentArtifacts(Request $request, Incident $incident, IncidentMessage $message): bool
    {
        $user = $request->user();

        return $this->canAccessIncident($request, $incident)
            && (int) $message->incident_id === (int) $incident->id
            && (int) $incident->operator_id === (int) $user->id
            && (($user->role?->value ?? (string) $user->role) === UserRole::Operator->value);
    }

    /**
     * @param  array<string, mixed>|null  $sender
     * @return array{id:int,role:string}
     */
    private function resolveSenderPayload(?array $sender, Incident $incident, mixed $user): array
    {
        $default = [
            'id' => (int) $user->id,
            'role' => $user->role?->value ?? (string) $user->role,
        ];

        if (!is_array($sender) || ($user->role ?? null) !== UserRole::Operator) {
            return $default;
        }

        $senderId = (int) ($sender['id'] ?? 0);
        $senderRole = (string) ($sender['role'] ?? '');

        if (
            ($senderRole === UserRole::Caller->value && $senderId !== (int) $incident->caller_id)
            || ($senderRole === UserRole::Operator->value && $senderId !== (int) $user->id)
        ) {
            abort(422, 'Invalid sender payload for incident message persistence.');
        }

        return [
            'id' => $senderId,
            'role' => $senderRole,
        ];
    }

    private function usesLegacySenderSnapshotColumns(): bool
    {
        return Schema::hasColumn('incident_messages', 'sender_name')
            && Schema::hasColumn('incident_messages', 'sender_avatar');
    }

    private function normalizeAttachmentType(?string $requestedType, UploadedFile $file): string
    {
        $normalized = strtolower(trim((string) $requestedType));

        if (in_array($normalized, ['image', 'video', 'audio', 'file'], true)) {
            return $normalized;
        }

        $mimeType = strtolower((string) ($file->getMimeType() ?: ''));

        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        return 'file';
    }

    private function generateAttachmentThumbnail(string $storedPath, string $type): ?string
    {
        return match ($type) {
            'image' => $this->generateImageThumbnail($storedPath),
            'video' => $this->generateVideoThumbnail($storedPath),
            default => null,
        };
    }

    private function generateImageThumbnail(string $storedPath): ?string
    {
        $sourcePath = Storage::disk('public')->path($storedPath);

        if (! is_file($sourcePath)) {
            return null;
        }

        $imageInfo = @getimagesize($sourcePath);
        $mimeType = strtolower((string) ($imageInfo['mime'] ?? ''));

        $source = match ($mimeType) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if (! $source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return null;
        }

        $maxDimension = 512;
        $scale = min($maxDimension / $width, $maxDimension / $height, 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($thumbnail, true);
        imagesavealpha($thumbnail, true);
        $background = imagecolorallocate($thumbnail, 18, 26, 40);
        imagefill($thumbnail, 0, 0, $background);

        imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        $thumbnailPath = $this->thumbnailPathFor($storedPath);
        $targetPath = Storage::disk('public')->path($thumbnailPath);
        $targetDirectory = dirname($targetPath);

        if (! is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0777, true);
        }

        $written = imagejpeg($thumbnail, $targetPath, 82);

        imagedestroy($thumbnail);
        imagedestroy($source);

        return $written ? $thumbnailPath : null;
    }

    private function generateVideoThumbnail(string $storedPath): ?string
    {
        $sourcePath = Storage::disk('public')->path($storedPath);

        if (! is_file($sourcePath)) {
            return null;
        }

        $thumbnailPath = $this->thumbnailPathFor($storedPath);
        $targetPath = Storage::disk('public')->path($thumbnailPath);
        $targetDirectory = dirname($targetPath);

        if (! is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0777, true);
        }

        $process = new Process([
            $this->mediaBinaries->ffmpeg(),
            '-y',
            '-i',
            $sourcePath,
            '-ss',
            '00:00:00.000',
            '-frames:v',
            '1',
            '-vf',
            'scale=512:-2',
            $targetPath,
        ]);

        $process->run();

        return $process->isSuccessful() && is_file($targetPath)
            ? $thumbnailPath
            : null;
    }

    private function thumbnailPathFor(string $storedPath): string
    {
        $directory = trim(str_replace('\\', '/', dirname($storedPath)), './');
        $filename = pathinfo($storedPath, PATHINFO_FILENAME);

        return ltrim($directory.'/'.$filename.'_thumb.jpg', '/');
    }
}
