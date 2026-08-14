<?php

namespace App\Support\Media;

use App\Support\Settings\SettingsService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FinalizedMediaStorage
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function put(string $key, string $contents): void
    {
        $key = $this->normalizeKey($key);
        $root = $this->archiveRoot();

        if ($root === null) {
            Storage::disk('public')->put($key, $contents);

            return;
        }

        $path = $this->path($key);
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to prepare finalized media archive directory.');
        }

        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write finalized media to archive storage.');
        }
    }

    public function delete(string $key): void
    {
        $key = $this->normalizeKey($key);
        $root = $this->archiveRoot();

        if ($root === null) {
            Storage::disk('public')->delete($key);

            return;
        }

        $path = $this->path($key);

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException('Unable to delete finalized media from archive storage.');
        }
    }

    public function exists(string $key): bool
    {
        $key = $this->normalizeKey($key);
        $root = $this->archiveRoot();

        return $root === null
            ? Storage::disk('public')->exists($key)
            : is_file($this->path($key));
    }

    public function path(string $key): string
    {
        $key = $this->normalizeKey($key);
        $root = $this->archiveRoot();

        if ($root === null) {
            return Storage::disk('public')->path($key);
        }

        return $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $key);
    }

    public function size(string $key): ?int
    {
        if (! $this->exists($key)) {
            return null;
        }

        $root = $this->archiveRoot();

        return $root === null
            ? Storage::disk('public')->size($this->normalizeKey($key))
            : (filesize($this->path($key)) ?: null);
    }

    public function mimeType(string $key): ?string
    {
        if (! $this->exists($key)) {
            return null;
        }

        $root = $this->archiveRoot();

        if ($root === null) {
            return Storage::disk('public')->mimeType($this->normalizeKey($key));
        }

        $mime = @mime_content_type($this->path($key));

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    public function assertWritable(): void
    {
        $root = $this->archiveRoot();

        if ($root === null) {
            return;
        }

        if (! is_dir($root) && ! @mkdir($root, 0777, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to prepare finalized media archive root.');
        }

        if (! is_writable($root)) {
            throw new RuntimeException('Finalized media archive root is not writable.');
        }
    }

    private function archiveRoot(): ?string
    {
        $root = trim((string) $this->settings->get('media_archive_root', ''));
        $root = trim($root, " \t\n\r\0\x0B\"'");

        if ($root === '') {
            return null;
        }

        if (! $this->isAbsolutePath($root)) {
            throw new RuntimeException('Finalized media archive root must be an absolute local or UNC path.');
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    }

    private function normalizeKey(string $key): string
    {
        $key = str_replace('\\', '/', trim($key));
        $key = preg_replace('#/+#', '/', $key) ?? '';
        $key = ltrim($key, '/');

        if (
            $key === ''
            || str_contains($key, '..')
            || preg_match('/^[A-Za-z]:/', $key) === 1
            || str_contains($key, "\0")
        ) {
            throw new RuntimeException('Invalid finalized media storage key.');
        }

        return $key;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '//')
            || str_starts_with($path, '/');
    }
}
