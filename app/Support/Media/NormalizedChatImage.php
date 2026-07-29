<?php

namespace App\Support\Media;

final readonly class NormalizedChatImage
{
    public function __construct(
        public string $contents,
        public int $width,
        public int $height,
        public string $storedFilename,
        public string $storedMimeType,
        public int $storedSizeBytes,
        public string $sha256,
    ) {}
}
