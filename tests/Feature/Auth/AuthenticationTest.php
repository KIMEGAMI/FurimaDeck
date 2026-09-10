<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SuspiciousLoginNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('furimadeck.cutover_enabled', true);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('furimadeck-dashboard', absolute: false));
    }

    public function test_users_are_sent_to_the_furimadeck_dashboard_after_login_during_cutover(): void
    {
        config()->set('furimadeck.cutover_enabled', true);
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('furimadeck-dashboard', absolute: false));
    }

    public function test_users_can_authenticate_with_uppercase_email_input(): void
    {
        $user = User::factory()->create([
            'email' => 'case-login@example.com',
        ]);

        $response = $this->post('/login', [
            'email' => 'CASE-LOGIN@EXAMPLE.COM',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('furimadeck-dashboard', absolute: false));
    }

    public function test_first_successful_login_records_security_baseline_without_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeader('User-Agent', 'Known Browser')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $user->refresh();

        $this->assertNotNull($user->last_login_at);
        $this->assertSame('203.0.113.10', $user->last_login_ip);
        $this->assertNotNull($user->last_login_user_agent_hash);
        $this->assertNull($user->suspicious_login_detected_at);

        Notification::assertNothingSent();
    }

    public function test_suspicious_login_from_new_ip_sends_security_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'last_login_at' => now()->subDay(),
            'last_login_ip' => '203.0.113.10',
            'last_login_user_agent_hash' => hash('sha256', 'Known Browser'),
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('User-Agent', 'Known Browser')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $user->refresh();

        $this->assertSame('198.51.100.20', $user->last_login_ip);
        $this->assertNotNull($user->suspicious_login_detected_at);

        Notification::assertSentTo($user, SuspiciousLoginNotification::class);
    }

    public function test_users_can_authenticate_using_the_demo_login(): void
    {
        config([
            'demo.user_email' => 'demo@example.com',
            'demo.user_password' => 'demo-password',
        ]);

        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'demo-password',
        ]);

        $response = $this->post(route('login.demo'));

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('furimadeck-dashboard', absolute: false));
    }

    public function test_demo_user_is_sent_to_the_furimadeck_dashboard_during_cutover(): void
    {
        config([
            'furimadeck.cutover_enabled' => true,
            'demo.user_email' => 'demo@example.com',
            'demo.user_password' => 'demo-password',
        ]);
        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'demo-password',
        ]);

        $response = $this->post(route('login.demo'));

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('furimadeck-dashboard', absolute: false));
    }

    public function test_unverified_demo_user_can_authenticate_using_the_demo_login(): void
    {
        config([
            'demo.user_email' => 'demo@example.com',
            'demo.user_password' => 'demo-password',
        ]);

        $user = User::factory()->unverified()->create([
            'email' => 'demo@example.com',
            'password' => 'demo-password',
        ]);

        $response = $this->post(route('login.demo'));

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('furimadeck-dashboard', absolute: false));
    }

    public function test_unverified_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_unverified_users_cannot_open_authenticated_app_pages(): void
    {
        $user = User::factory()->unverified()->create();

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice', absolute: false));

        $this
            ->actingAs($user)
            ->get(route('auction-items.index'))
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_login_attempts_are_limited_by_ip_even_with_different_emails(): void
    {
        config([
            'auth_security.login_max_attempts' => 10,
            'auth_security.login_ip_max_attempts' => 2,
            'auth_security.login_decay_seconds' => 60,
        ]);

        RateLimiter::clear('login-ip|203.0.113.30');

        foreach (['one@example.com', 'two@example.com'] as $email) {
            $this
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
                ->post('/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ]);
        }

        $this->assertTrue(RateLimiter::tooManyAttempts('login-ip|203.0.113.30', 2));

        $this
            ->from('/login')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.30'])
            ->post('/login', [
                'email' => 'three@example.com',
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
