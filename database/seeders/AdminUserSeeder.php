<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.initial_password');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($password) || $password === '') {
            $this->command?->warn('FurimaDeck管理者のメールアドレスまたは初期パスワードが未設定か無効です。管理者は作成していません。');

            return;
        }

        $email = Str::lower($email);

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
