<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('furimadeck.cutover_enabled', true);
    }

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_verification_email_resend_does_not_fail_when_mail_transport_fails(): void
    {
        $user = User::factory()->unverified()->create();

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('SMTP failed'));

        $this
            ->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-send-failed');

        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_email_resend_does_not_fail_when_notification_throws_unexpected_error(): void
    {
        $user = User::factory()->unverified()->create();

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Unexpected mail failure'));

        $this
            ->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-send-failed');

        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_link_does_not_authenticate_guests(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->get($verificationUrl);

        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_verify_their_own_email(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('furimadeck-dashboard', absolute: false).'?verified=1');
    }

    public function test_authenticated_user_cannot_verify_another_users_email(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($otherUser)->get($verificationUrl)->assertForbidden();

        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($otherUser);
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
    }
}
