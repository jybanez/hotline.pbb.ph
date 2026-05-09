<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CurrentUserController extends Controller
{
    private const AVATAR_SIZE = 256;

    public function show(): JsonResponse
    {
        return response()->json(request()->user());
    }

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $avatarPath = $user->avatar_path;

        if ($request->hasFile('avatar')) {
            $avatarPath = $this->storeAvatar($request->file('avatar'), (int) $user->id);
        }

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'],
            'avatar_path' => $avatarPath,
        ])->save();

        return response()->json($user->fresh());
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->forceFill([
            'password' => Hash::make($validated['new_password']),
        ])->save();

        return response()->json([
            'ok' => true,
            'message' => 'Password updated.',
        ]);
    }

    private function storeAvatar(UploadedFile $file, int $userId): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages([
                'avatar' => 'Avatar processing is unavailable on this server.',
            ]);
        }

        $sourceBytes = $file->get();
        $sourceImage = @imagecreatefromstring($sourceBytes);

        if ($sourceImage === false) {
            throw ValidationException::withMessages([
                'avatar' => 'The uploaded avatar could not be processed.',
            ]);
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $cropSize = min($sourceWidth, $sourceHeight);
        $cropX = (int) floor(($sourceWidth - $cropSize) / 2);
        $cropY = (int) floor(($sourceHeight - $cropSize) / 2);

        $targetImage = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        $background = imagecolorallocate($targetImage, 255, 255, 255);
        imagefill($targetImage, 0, 0, $background);

        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            $cropX,
            $cropY,
            self::AVATAR_SIZE,
            self::AVATAR_SIZE,
            $cropSize,
            $cropSize,
        );

        ob_start();
        imagejpeg($targetImage, null, 85);
        $encoded = ob_get_clean();

        imagedestroy($sourceImage);
        imagedestroy($targetImage);

        if (!is_string($encoded) || $encoded === '') {
            throw ValidationException::withMessages([
                'avatar' => 'The uploaded avatar could not be encoded.',
            ]);
        }

        $path = sprintf('avatars/%d.jpg', $userId);
        Storage::disk('public')->put($path, $encoded);

        return $path;
    }
}
