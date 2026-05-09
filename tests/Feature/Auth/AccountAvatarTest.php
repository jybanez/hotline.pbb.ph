<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountAvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_authenticated_user_can_upload_replacement_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'email' => 'operator@example.test',
            'password' => Hash::make('secret-password'),
            'role' => UserRole::Operator,
            'status' => UserStatus::Active,
            'remember_token' => null,
        ]);

        $response = $this->actingAs($user)->post('/api/user', [
            'name' => 'Updated Operator',
            'email' => 'operator@example.test',
            'mobile' => '09170000002',
            'avatar' => UploadedFile::fake()->image('avatar.png', 240, 180),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('name', 'Updated Operator')
            ->assertJsonPath('mobile', '09170000002');

        Storage::disk('public')->assertExists("avatars/{$user->id}.jpg");

        $storedPath = Storage::disk('public')->path("avatars/{$user->id}.jpg");
        [$width, $height] = getimagesize($storedPath);

        $this->assertSame(256, $width);
        $this->assertSame(256, $height);

        $user->refresh();

        $this->assertSame("avatars/{$user->id}.jpg", $user->avatar_path);
        $this->assertNotNull($user->avatar);
        $this->assertStringContainsString("/storage/avatars/{$user->id}.jpg?v=", (string) $user->avatar);
    }
}
