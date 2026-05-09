<?php

namespace App\Support\Media;

class MediaBinaryResolver
{
    public function ffmpeg(): string
    {
        return $this->resolve(
            env('HOTLINE_FFMPEG_BINARY'),
            [
                base_path('bin/ffmpeg/ffmpeg.exe'),
                base_path('bin/ffmpeg/ffmpeg'),
            ],
            'ffmpeg',
        );
    }

    public function ffprobe(): string
    {
        return $this->resolve(
            env('HOTLINE_FFPROBE_BINARY'),
            [
                base_path('bin/ffmpeg/ffprobe.exe'),
                base_path('bin/ffmpeg/ffprobe'),
            ],
            'ffprobe',
        );
    }

    /**
     * @param  array<int, string>  $localCandidates
     */
    private function resolve(mixed $configuredPath, array $localCandidates, string $fallback): string
    {
        $configured = is_string($configuredPath) ? trim($configuredPath) : '';

        if ($configured !== '') {
            return $configured;
        }

        foreach ($localCandidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $fallback;
    }
}
