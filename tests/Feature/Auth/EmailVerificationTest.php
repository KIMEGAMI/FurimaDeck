<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\EmailVerificationDelivery;
use App\Services\EmailVerificationSession;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Resend\Contracts\Client as ResendClient;
use Resend\Resource;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
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

    public function test_guest_can_view_verification_notice(): void
    {
        $this->get(route('verification.notice'))->assertOk();
        $this->assertGuest();
    }

    public function test_resend_verification_acceptance_logs_provider_id_without_recipient_data(): void
    {
        config()->set('mail.default', 'resend');
        $user = User::factory()->unverified()->create([
            'email' => 'private-recipient@example.test',
        ]);
        $providerResponse = new class
        {
            public function getMessageId(): string
            {
                return 'resend-message-id-test';
            }
        };

        Log::shouldReceive('info')
            ->once()
            ->with('Email verification accepted by Resend.', [
                'user_id' => $user->getKey(),
                'provider_message_id' => 'resend-message-id-test',
            ]);

        Event::dispatch(new NotificationSent(
            $user,
            new VerifyEmail,
            'mail',
            $providerResponse,
        ));
    }

    public function test_verification_acceptance_does_not_log_for_other_mailers_or_notifications(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.example.test');
        $user = User::factory()->unverified()->create();
        Log::shouldReceive('info')->never();

        Event::dispatch(new NotificationSent(
            $user,
            new VerifyEmail,
            'mail',
            (object) ['message_id' => 'smtp-message-id'],
        ));

        config()->set('mail.default', 'resend');
        Event::dispatch(new NotificationSent(
            $user,
            new BaseNotification,
            'mail',
            (object) ['message_id' => 'other-notification-id'],
        ));
    }

    public function test_resend_smtp_verification_acceptance_logs_provider_id_without_recipient_data(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.resend.com');
        $user = User::factory()->unverified()->create([
            'email' => 'private-recipient@example.test',
        ]);
        $providerResponse = new class
        {
            public function getMessageId(): string
            {
                return 'resend-smtp-message-id-test';
            }
        };

        Log::shouldReceive('info')
            ->once()
            ->with('Email verification accepted by Resend.', [
                'user_id' => $user->getKey(),
                'provider_message_id' => 'resend-smtp-message-id-test',
            ]);

        Event::dispatch(new NotificationSent(
            $user,
            new VerifyEmail,
            'mail',
            $providerResponse,
        ));
    }

    public function test_resend_acceptance_is_not_reclassified_when_success_logging_fails(): void
    {
        config()->set('mail.default', 'resend');
        $user = User::factory()->unverified()->create();
        $providerResponse = new class
        {
            public function getMessageId(): string
            {
                return 'resend-message-id-test';
            }
        };

        Log::shouldReceive('info')
            ->once()
            ->andThrow(new RuntimeException('log backend unavailable'));

        Event::dispatch(new NotificationSent(
            $user,
            new VerifyEmail,
            'mail',
            $providerResponse,
        ));

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_real_resend_mail_transport_acceptance_id_is_logged_without_external_request(): void
    {
        config()->set('mail.default', 'resend');

        $user = User::factory()->unverified()->create([
            'email' => 'private-recipient@example.test',
        ]);
        $resendClient = new class implements ResendClient
        {
            public array $payloads = [];

            public function __get(string $name): object
            {
                return new class($this)
                {
                    public function __construct(private readonly object $client) {}

                    public function send(array $payload): object
                    {
                        $this->client->payloads[] = $payload;

                        return new Resource(['id' => 'resend-message-id-transport']);
                    }
                };
            }
        };

        Mail::extend('resend', fn ($app) => new ResendTransport($resendClient));
        Mail::purge('resend');

        Log::shouldReceive('info')
            ->once()
            ->with('Email verification accepted by Resend.', [
                'user_id' => $user->getKey(),
                'provider_message_id' => 'resend-message-id-transport',
            ]);

        $this->assertTrue(app(EmailVerificationDelivery::class)->send($user, 'transport_test'));
        $this->assertCount(1, $resendClient->payloads);
        $expectedFrom = (new Address(config('mail.from.address'), config('mail.from.name')))->toString();
        $this->assertSame($expectedFrom, $resendClient->payloads[0]['from']);
        $this->assertSame([$user->email], $resendClient->payloads[0]['to']);
    }

    public function test_guest_can_resend_verification_email_with_valid_credentials(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'password',
        ]);

        $this->post(route('verification.send'), [
            'email' => 'pending@example.com',
            'password' => 'password',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-requested');

        $this->assertGuest();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_guest_verification_resend_does_not_reveal_invalid_credentials(): void
    {
        Notification::fake();
        User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'password',
        ]);

        $this->post(route('verification.send'), [
            'email' => 'pending@example.com',
            'password' => 'wrong-password',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-requested')
            ->assertSessionHasNoErrors();

        $this->assertGuest();
        Notification::assertNothingSent();
    }

    public function test_guest_verification_resend_returns_generic_status_when_delivery_fails(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'password',
        ]);

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('SMTP failed'));

        $this->post(route('verification.send'), [
            'email' => 'pending@example.com',
            'password' => 'password',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-requested')
            ->assertSessionHasNoErrors();

        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
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

    public function test_guest_can_verify_email_and_is_signed_in_afterward(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->withSession([EmailVerificationSession::PENDING_USER_ID => $user->id])->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
        $response
            ->assertRedirect(route('furimadeck-dashboard', absolute: false).'?verified=1')
            ->assertSessionMissing('status');
    }

    public function test_verification_link_without_the_matching_registration_session_does_not_log_in(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->get($verificationUrl)
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas('status', 'email-verified-login');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertGuest();
    }

    public function test_verification_link_for_another_account_does_not_consume_the_pending_registration_session(): void
    {
        $pendingUser = User::factory()->unverified()->create();
        $verifiedUser = User::factory()->unverified()->create();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $verifiedUser->id, 'hash' => sha1($verifiedUser->email)]
        );

        $this->withSession([EmailVerificationSession::PENDING_USER_ID => $pendingUser->id])
            ->get($verificationUrl)
            ->assertRedirect(route('login', absolute: false))
            ->assertSessionHas(EmailVerificationSession::PENDING_USER_ID, $pendingUser->id);

        $this->assertTrue($verifiedUser->fresh()->hasVerifiedEmail());
        $this->assertGuest();
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
