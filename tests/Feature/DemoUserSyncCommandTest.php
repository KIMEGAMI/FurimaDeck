<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoUserSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_configured_demo_user_without_exposing_credentials(): void
    {
        config([
            'demo.user_enabled' => true,
            'demo.user_email' => 'demo@example.com',
            'demo.user_password' => 'demo-password',
        ]);

        $this->artisan('furimadeck:sync-demo-user')
            ->expectsOutputToContain('認証情報は表示していません')
            ->assertSuccessful();

        $user = User::query()->where('email', 'demo@example.com')->sole();
        $this->assertTrue(Hash::check('demo-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_admin);
    }

    public function test_it_synchronizes_an_existing_demo_password_only_when_requested(): void
    {
        config([
            'demo.user_enabled' => true,
            'demo.user_email' => 'demo@example.com',
            'demo.user_password' => 'new-demo-password',
        ]);
        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'old-demo-password',
        ]);

        $this->artisan('furimadeck:sync-demo-user')->assertSuccessful();
        $this->assertTrue(Hash::check('old-demo-password', $user->fresh()->password));

        $this->artisan('furimadeck:sync-demo-user', ['--sync-password' => true])->assertSuccessful();
        $this->assertTrue(Hash::check('new-demo-password', $user->fresh()->password));
    }

    public function test_it_reports_demo_data_counts_without_disclosing_credentials(): void
    {
        config([
            'demo.user_enabled' => true,
            'demo.user_email' => 'demo@example.com',
            'demo.user_password' => 'demo-password',
        ]);
        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'email_verified_at' => now(),
        ]);

        $this->artisan('furimadeck:sync-demo-user', ['--status' => true])
            ->expectsOutput('DEMO_CONFIGURATION=READY')
            ->expectsOutput('DEMO_USER_EXISTS=YES')
            ->expectsOutput('DEMO_USER_VERIFIED=YES')
            ->expectsOutput('DEMO_DATA_TABLES=UNAVAILABLE')
            ->assertSuccessful();
    }
}
