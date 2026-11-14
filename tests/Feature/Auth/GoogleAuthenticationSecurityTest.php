<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_google_email_cannot_be_linked_to_an_existing_account(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('google-user-id', 'owner@example.com', false);

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_verified_google_email_can_be_linked_to_an_existing_account(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'owner@example.com',
            'google_id' => null,
        ]);

        $session = $this->app['session']->driver();
        $session->start();
        $initialSessionId = $session->getId();
        $this->fakeGoogleUser('google-user-id', 'owner@example.com', true);

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertNotSame($initialSessionId, $this->app['session']->driver()->getId());
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-user-id', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verified_google_email_can_be_linked_with_uppercase_email_input(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'case-google@example.com',
            'google_id' => null,
        ]);

        $this->fakeGoogleUser('google-case-user-id', 'CASE-GOOGLE@EXAMPLE.COM', true);

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('google-case-user-id', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verified_google_email_cannot_link_to_an_account_owned_by_a_different_google_id(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'google_id' => 'google-old-user-id',
        ]);

        $this->fakeGoogleUser('google-new-user-id', 'owner@example.com', true);

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame('google-old-user-id', $user->fresh()->google_id);
    }

    public function test_google_login_failure_returns_to_login_screen(): void
    {
        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andThrow(new RuntimeException('Google is unavailable.'));

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_google_http_failure_logs_only_safe_response_diagnostics(): void
    {
        $exception = new RequestException(
            'Private response details must not be logged.',
            new Request('POST', 'https://oauth2.googleapis.com/token'),
            new Response(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant","error_description":"private response details"}')
        );

        Log::shouldReceive('warning')
            ->once()
            ->with('Google login failed before user resolution.', [
                'error_class' => RequestException::class,
                'http_status' => 400,
                'oauth_error' => 'invalid_grant',
            ]);

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andThrow($exception);

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_google_login_can_explicitly_bypass_an_inherited_proxy(): void
    {
        $this->app['config']->set('services.google.direct_connection', true);
        $provider = $this->fakeGoogleUser('google-user-id', 'owner@example.com', true);

        $response = $this->get(route('google.callback'));

        $response->assertRedirect(route('dashboard'));
        $this->assertInstanceOf(Client::class, $provider->httpClient);
        $this->assertSame('', $provider->httpClient->getConfig('proxy'));
    }

    private function fakeGoogleUser(string $id, string $email, bool $emailVerified): object
    {
        $socialiteUser = (new SocialiteUser)->setRaw([
            'email_verified' => $emailVerified,
        ])->map([
            'id' => $id,
            'name' => 'Google User',
            'email' => $email,
        ]);

        $provider = new class($socialiteUser)
        {
            public ?Client $httpClient = null;

            public function __construct(private SocialiteUser $user) {}

            public function setHttpClient(Client $httpClient): self
            {
                $this->httpClient = $httpClient;

                return $this;
            }

            public function user(): SocialiteUser
            {
                return $this->user;
            }
        };

        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($provider);

        return $provider;
    }
}
