<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('FURIMADECK_ADMIN_EMAIL') ?: env('ADMIN_EMAIL', 'admin@shinji.work');
        $password = env('FURIMADECK_ADMIN_INITIAL_PASSWORD') ?: env('ADMIN_PASSWORD');

        if (! is_string($password) || $password === '') {
            $this->command?->warn('FURIMADECK_ADMIN_INITIAL_PASSWORD is not set. Admin user was not created.');

            return;
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'is_admin' => true,
            ]
        );
    }
}
