<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

final class ChatPhotoWebpNormalizer
{
    public function normalize(UploadedFile $file, int $maxLongEdge = 1600, int $quality = 84): NormalizedChatImage
    {
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('WebP encoding is unavailable on this server.');
        }

        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            throw new RuntimeException('Uploaded image file is unavailable.');
        }

        $source = $this->decode($sourcePath);
        if (! $source) {
            throw new RuntimeException('Uploaded image could not be decoded.');
        }

        $source = $this->applyExifOrientation($sourcePath, $source);
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);

            throw new RuntimeException('Uploaded image dimensions are invalid.');
        }

        $scale = min($maxLongEdge / max($width, $height), 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($target, true);
        imagesavealpha($target, true);
        $background = imagecolorallocatealpha($target, 255, 255, 255, 0);
        imagefill($target, 0, 0, $background);

        imagecopyresampled(
            $target,
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

        ob_start();
        $encoded = imagewebp($target, null, $quality);
        $contents = (string) ob_get_clean();

        imagedestroy($target);
        imagedestroy($source);

        if (! $encoded || $contents === '') {
            throw new RuntimeException('Uploaded image could not be encoded as WebP.');
        }

        $baseName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'chat-photo';
        $storedFilename = sprintf('%s_%s.webp', now()->format('YmdHis'), Str::limit($baseName, 48, ''));

        return new NormalizedChatImage(
            contents: $contents,
            width: $targetWidth,
            height: $targetHeight,
            storedFilename: $storedFilename,
            storedMimeType: 'image/webp',
            storedSizeBytes: strlen($contents),
            sha256: hash('sha256', $contents),
        );
    }

    /**
     * @return \GdImage|false
     */
    private function decode(string $path): mixed
    {
        $info = @getimagesize($path);
        $mimeType = strtolower((string) ($info['mime'] ?? ''));

        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function applyExifOrientation(string $path, mixed $image): mixed
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $info = @getimagesize($path);
        $mimeType = strtolower((string) ($info['mime'] ?? ''));

        if (! in_array($mimeType, ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            3 => imagerotate($image, 180, 0) ?: $image,
            6 => imagerotate($image, -90, 0) ?: $image,
            8 => imagerotate($image, 90, 0) ?: $image,
            default => $image,
        };
    }
}
