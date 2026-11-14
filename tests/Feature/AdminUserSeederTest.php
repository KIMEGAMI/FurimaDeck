<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        $connection['url'] = null;
        config([
            'database.connections.sqlite' => $connection,
            'database.default' => 'sqlite',
        ]);
        DB::purge('sqlite');

        $this->artisan('migrate', [
            '--database' => 'sqlite',
            '--path' => 'database/migrations',
        ])->assertSuccessful();
    }

    public function test_it_creates_the_configured_furimadeck_admin(): void
    {
        $password = Str::random(32);
        config([
            'admin.email' => 'Admin-Test@Example.Test',
            'admin.initial_password' => $password,
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::query()->where('email', 'admin-test@example.test')->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check($password, $admin->password));
    }

    public function test_it_does_not_fall_back_to_a_legacy_admin_email_when_configuration_is_missing(): void
    {
        config([
            'admin.email' => null,
            'admin.initial_password' => null,
        ]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@shinji.work']);
    }
}
