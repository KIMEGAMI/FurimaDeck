<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
        $response->assertSee('パスワード再設定', false);
        $response->assertSee('再設定URLを送信', false);
    }

    public function test_login_screen_has_password_reset_link(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('パスワードを忘れた方', false);
        $response->assertSee(route('password.request'), false);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this
            ->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email]);

        $response
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', '該当するアカウントがある場合は、パスワード再設定用メールを送信します。受信トレイをご確認ください。');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_receives_generic_password_reset_feedback(): void
    {
        Notification::fake();

        $response = $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'unknown@example.com']);

        $response
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', '該当するアカウントがある場合は、パスワード再設定用メールを送信します。受信トレイをご確認ください。')
            ->assertSessionHasNoErrors();
        Notification::assertNothingSent();
    }

    public function test_password_reset_throttle_feedback_does_not_reveal_account_existence(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_THROTTLED);

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'known@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', '該当するアカウントがある場合は、パスワード再設定用メールを送信します。受信トレイをご確認ください。')
            ->assertSessionHasNoErrors();
    }

    public function test_password_reset_route_throttles_by_ip_before_sending_another_notification(): void
    {
        Notification::fake();
        config(['furimadeck.public_rate_limits.password_reset_per_minute' => 1]);

        $firstUser = User::factory()->create(['email' => 'first-reset@example.com']);
        $secondUser = User::factory()->create(['email' => 'second-reset@example.com']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
            ->post('/forgot-password', ['email' => $firstUser->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($firstUser, ResetPassword::class);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
            ->post('/forgot-password', ['email' => $secondUser->email])
            ->assertTooManyRequests();

        Notification::assertNotSentTo($secondUser, ResetPassword::class);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.52'])
            ->post('/forgot-password', ['email' => $secondUser->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($secondUser, ResetPassword::class);
    }

    public function test_reset_password_link_can_be_requested_with_uppercase_email_input(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset-case@example.com',
        ]);

        $this->post('/forgot-password', ['email' => 'RESET-CASE@EXAMPLE.COM']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_request_returns_validation_error_when_mail_transport_fails(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new TransportException('SMTP authentication failed.'));

        $response = $this
            ->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'user@example.com']);

        $response
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');
    }

    public function test_reset_password_request_returns_validation_error_when_unexpected_mail_error_happens(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new RuntimeException('Unexpected mail failure.'));

        $response = $this
            ->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'USER@EXAMPLE.COM']);

        $response
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email')
            ->assertSessionHasInput('email', 'user@example.com');
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);
            $response->assertSee('新しいパスワードを設定', false);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_password_reset_token_cannot_be_reused(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            $payload = ['token' => $notification->token, 'email' => $user->email, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
            $this->post('/reset-password', $payload)->assertRedirect(route('login'));
            $this->from('/reset-password/'.$notification->token)->post('/reset-password', $payload)->assertRedirect('/reset-password/'.$notification->token)->assertSessionHasErrors('email');

            return true;
        });
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subHours(25)]);
            $this->from('/reset-password/'.$notification->token)->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertRedirect('/reset-password/'.$notification->token)->assertSessionHasErrors('email');

            return true;
        });
    }
}
