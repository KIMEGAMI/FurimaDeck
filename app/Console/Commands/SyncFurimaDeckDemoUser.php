<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SyncFurimaDeckDemoUser extends Command
{
    protected $signature = 'furimadeck:sync-demo-user
        {--sync-password : Synchronize the existing demo user password with the configured environment value.}
        {--status : Show demo data counts without displaying credentials or personal information.}';

    protected $description = 'Create the configured FurimaDeck demo user without displaying credentials.';

    public function handle(): int
    {
        $email = config('demo.user_email');
        $password = config('demo.user_password');

        if ($this->option('status')) {
            return $this->status($email, $password);
        }

        if (! (bool) config('demo.user_enabled') || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($password) || $password === '') {
            $this->error('デモユーザー設定が未完了です。秘密値は表示していません。');

            return self::FAILURE;
        }

        $email = Str::lower($email);
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null && ! $this->option('sync-password')) {
            if ($existingUser->email_verified_at === null) {
                $existingUser->forceFill(['email_verified_at' => now()])->save();
            }

            $this->info('デモユーザーは既に存在します。パスワードは変更していません。');

            return self::SUCCESS;
        }

        $created = $existingUser === null;

        DB::transaction(function () use ($existingUser, $email, $password): void {
            if ($existingUser === null) {
                User::query()->create([
                    'name' => 'Demo User',
                    'email' => $email,
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                    'is_admin' => false,
                ]);

                return;
            }

            $existingUser->forceFill([
                'password' => Hash::make($password),
                'email_verified_at' => $existingUser->email_verified_at ?? now(),
            ])->save();
        });

        $this->info($created
            ? 'デモユーザーを作成しました。認証情報は表示していません。'
            : 'デモユーザーのパスワードを同期しました。認証情報は表示していません。');

        return self::SUCCESS;
    }

    private function status(mixed $email, mixed $password): int
    {
        $isConfigured = (bool) config('demo.user_enabled')
            && is_string($email)
            && filter_var($email, FILTER_VALIDATE_EMAIL)
            && is_string($password)
            && $password !== '';
        $this->line('DEMO_CONFIGURATION='.($isConfigured ? 'READY' : 'INCOMPLETE'));

        if (! $isConfigured) {
            return self::FAILURE;
        }

        $user = User::query()->where('email', Str::lower($email))->first();
        $this->line('DEMO_USER_EXISTS='.($user !== null ? 'YES' : 'NO'));

        if ($user === null) {
            return self::SUCCESS;
        }

        $this->line('DEMO_USER_VERIFIED='.($user->email_verified_at !== null ? 'YES' : 'NO'));
        if (! Schema::hasTable('products') || ! Schema::hasTable('listings') || ! Schema::hasTable('sales')) {
            $this->line('DEMO_DATA_TABLES=UNAVAILABLE');

            return self::SUCCESS;
        }

        $this->line('DEMO_PRODUCTS='.$user->products()->count());
        $this->line('DEMO_LISTINGS='.$user->listings()->count());
        $this->line('DEMO_SALES='.$user->sales()->count());
        $this->line('DEMO_SALES_CURRENT_MONTH='.$user->sales()
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->where('sold_at', '>=', now()->startOfMonth())
            ->where('sold_at', '<', now()->addMonth()->startOfMonth())
            ->count());

        return self::SUCCESS;
    }
}
