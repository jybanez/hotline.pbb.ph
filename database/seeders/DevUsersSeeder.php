<?php

namespace Database\Seeders;

use App\Domain\Shared\Enums\UserRole;
use App\Domain\Shared\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'PBB Admin',
                'email' => 'admin@hotline.pbb.ph',
                'mobile' => '09170000001',
                'role' => UserRole::Admin,
            ],
            [
                'name' => 'PBB Operator',
                'email' => 'operator@hotline.pbb.ph',
                'mobile' => '09170000002',
                'role' => UserRole::Operator,
            ],
            [
                'name' => 'PBB Caller',
                'email' => 'caller@hotline.pbb.ph',
                'mobile' => '09170000003',
                'role' => UserRole::Caller,
            ],
        ];

        foreach ($users as $row) {
            User::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'mobile' => $row['mobile'],
                    'role' => $row['role'],
                    'status' => UserStatus::Active,
                    'password' => Hash::make('password'),
                ],
            );
        }
    }
}
