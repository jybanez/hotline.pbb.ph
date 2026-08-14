<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Media\FinalizedMediaStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicStorageController extends Controller
{
    public function __construct(private readonly FinalizedMediaStorage $finalizedMedia)
    {
    }

    public function show(Request $request, string $path): BinaryFileResponse
    {
        $path = $this->normalizePath($path);
        abort_if($path === '', 404);

        if ($this->isFinalizedMediaKey($path)) {
            abort_if(! $this->finalizedMedia->exists($path), 404);

            return response()->file($this->finalizedMedia->path($path));
        }

        abort_if(! Storage::disk('public')->exists($path), 404);

        return response()->file(Storage::disk('public')->path($path));
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            return '';
        }

        return $path;
    }

    private function isFinalizedMediaKey(string $path): bool
    {
        return preg_match('#^incidents/\d+/media/#', $path) === 1
            || str_starts_with($path, 'diagnostics/operator-media-stream-tests/');
    }
}
