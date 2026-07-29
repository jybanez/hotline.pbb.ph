<?php

namespace Tests\Unit;

use App\Support\Media\ChatPhotoWebpNormalizer;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ChatPhotoWebpNormalizerTest extends TestCase
{
    public function test_normalizer_downscales_encodes_webp_and_hashes_authoritative_bytes(): void
    {
        $file = UploadedFile::fake()->image('large-scene.jpg', 2400, 1200);

        $result = (new ChatPhotoWebpNormalizer)->normalize($file, maxLongEdge: 1600, quality: 84);

        $this->assertSame('image/webp', $result->storedMimeType);
        $this->assertSame(1600, $result->width);
        $this->assertSame(800, $result->height);
        $this->assertStringEndsWith('.webp', $result->storedFilename);
        $this->assertSame(strlen($result->contents), $result->storedSizeBytes);
        $this->assertSame(hash('sha256', $result->contents), $result->sha256);
        $this->assertSame('image/webp', (string) getimagesizefromstring($result->contents)['mime']);
    }
}
