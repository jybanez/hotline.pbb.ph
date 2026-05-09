<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_caller_role_rows_are_migrated_to_citizen(): void
    {
        DB::table('users')->insert([
            'name' => 'Legacy Caller',
            'email' => 'legacy-caller@example.test',
            'password' => Hash::make('password'),
            'role' => 'caller',
            'status' => UserStatus::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_05_09_000001_rename_caller_role_to_citizen.php');

        $migration->up();

        $this->assertDatabaseHas('users', [
            'email' => 'legacy-caller@example.test',
            'role' => 'citizen',
        ]);

        $migration->down();

        $this->assertDatabaseHas('users', [
            'email' => 'legacy-caller@example.test',
            'role' => 'caller',
        ]);
    }
}
