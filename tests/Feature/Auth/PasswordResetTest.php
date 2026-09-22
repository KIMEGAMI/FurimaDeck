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

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
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
