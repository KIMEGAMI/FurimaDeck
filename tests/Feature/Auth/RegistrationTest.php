<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_and_are_sent_to_email_verification_notice(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $this->assertGuest();
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('verification.notice', absolute: false));
        $response->assertSessionHas('status', 'verification-link-requested');

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this
            ->get(route('dashboard'))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_registration_requests_are_throttled_before_another_user_is_created(): void
    {
        Notification::fake();
        config(['furimadeck.public_rate_limits.registration_per_minute' => 1]);

        $this->post('/register', [
            'name' => 'First User',
            'email' => 'first@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertRedirect(route('verification.notice', absolute: false));

        $this->post('/register', [
            'name' => 'Second User',
            'email' => 'second@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertTooManyRequests();

        $this->assertDatabaseCount('users', 1);
        Notification::assertSentTo(User::where('email', 'first@example.com')->firstOrFail(), VerifyEmail::class);
        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_registration_does_not_fail_when_verification_email_cannot_be_sent(): void
    {
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('SMTP failed'));

        $response = $this->post('/register', [
            'name' => 'Mail Failure User',
            'email' => 'mail-failure@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionHas('status', 'verification-link-send-failed');

        $this->assertDatabaseHas('users', [
            'email' => 'mail-failure@example.com',
        ]);

        $user = User::where('email', 'mail-failure@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());

        $this
            ->get(route('dashboard'))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_registration_does_not_fail_when_verification_notification_throws_unexpected_error(): void
    {
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Unexpected mail failure'));

        $response = $this->post('/register', [
            'name' => 'Unexpected Mail Failure User',
            'email' => 'unexpected-mail-failure@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionHas('status', 'verification-link-send-failed');

        $this->assertDatabaseHas('users', [
            'email' => 'unexpected-mail-failure@example.com',
        ]);

        $user = User::where('email', 'unexpected-mail-failure@example.com')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());

        $this
            ->get(route('dashboard'))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_unverified_existing_user_can_continue_registration_to_verification_notice(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'password',
        ]);

        $response = $this->post('/register', [
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionHas('status', 'verification-link-requested');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_unverified_existing_user_can_continue_when_verification_email_cannot_be_sent(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'pending-mail-failure@example.com',
            'password' => 'password',
        ]);

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('SMTP failed'));

        $response = $this->post('/register', [
            'name' => 'Pending Mail Failure User',
            'email' => 'pending-mail-failure@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionHas('status', 'verification-link-requested');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'pending-mail-failure@example.com',
        ]);
    }

    public function test_registration_response_does_not_reveal_a_verified_existing_email(): void
    {
        Notification::fake();

        User::factory()->create([
            'email' => 'registered@example.com',
            'password' => 'password',
        ]);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Registered User',
            'email' => 'registered@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionHas('status', 'verification-link-requested')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_registration_response_does_not_reveal_an_unverified_email_when_password_is_wrong(): void
    {
        Notification::fake();

        User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->post('/register', [
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password' => 'wrong-password',
            'password_confirmation' => 'wrong-password',
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ]);

        $this->assertGuest();
        $response
            ->assertRedirect(route('verification.notice', absolute: false))
            ->assertSessionHas('status', 'verification-link-requested')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_users_cannot_register_without_terms_and_privacy_agreement(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors(['terms_accepted', 'privacy_accepted']);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }
}
