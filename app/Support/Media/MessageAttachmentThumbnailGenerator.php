<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

final class MessageAttachmentThumbnailGenerator
{
    public function __construct(
        private readonly MediaBinaryResolver $mediaBinaries,
    ) {}

    public function generate(string $storedPath, string $type): ?string
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

        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

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
