<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'mantas.smulkstys@gmail.com'],
            [
                'name' => 'Mantas Šmulkštys',
                'password' => Hash::make('mansmuadmin123'),
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ]
        );

        User::updateOrCreate(
            ['email' => 'jonas.jonaitis@gmail.com'],
            [
                'name' => 'Jonas Jonaitis',
                'password' => Hash::make('regvartotojas123'),
                'role' => UserRole::User,
                'email_verified_at' => now(),
            ]
        );
    }
}
