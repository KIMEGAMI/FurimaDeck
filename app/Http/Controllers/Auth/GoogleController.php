<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginSecurityService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleController extends Controller
{
    public function __construct(private readonly LoginSecurityService $loginSecurity) {}

    public function redirect()
    {
        return $this->googleDriver()->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $googleUser = $this->googleDriver()->user();
        } catch (InvalidStateException) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Googleログインの有効期限が切れました。もう一度Googleログインをお試しください。',
                ]);
        } catch (Throwable $exception) {
            $context = [
                'error_class' => $exception::class,
            ];

            if ($exception instanceof RequestException) {
                $response = $exception->getResponse();
                if ($response) {
                    $context['http_status'] = $response->getStatusCode();
                    $payload = json_decode((string) $response->getBody(), true);
                    $oauthError = is_array($payload) ? ($payload['error'] ?? null) : null;
                    if (is_string($oauthError) && in_array($oauthError, [
                        'access_denied',
                        'invalid_client',
                        'invalid_grant',
                        'invalid_request',
                        'invalid_token',
                        'server_error',
                        'temporarily_unavailable',
                        'unauthorized_client',
                        'unsupported_grant_type',
                    ], true)) {
                        $context['oauth_error'] = $oauthError;
                    }
                }

                $transportErrno = $exception->getHandlerContext()['errno'] ?? null;
                if (is_int($transportErrno)) {
                    $context['transport_errno'] = $transportErrno;
                }
            }

            Log::warning('Google login failed before user resolution.', $context);

            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Googleログインに失敗しました。時間をおいてもう一度お試しください。',
                ]);
        }

        $googleId = (string) $googleUser->getId();
        $email = $googleUser->getEmail();
        $email = is_string($email) ? Str::lower($email) : null;

        $user = User::where('google_id', $googleId)->first();

        if (! $user) {
            if (! $email || ! $this->hasVerifiedGoogleEmail($googleUser)) {
                return redirect()
                    ->route('login')
                    ->withErrors([
                        'email' => 'Google アカウントのメールアドレスを確認できませんでした。',
                    ]);
            }

            $user = User::where('email', $email)->first();
        }

        if ($user && is_string($user->google_id) && $user->google_id !== '' && ! hash_equals($user->google_id, $googleId)) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'Googleログインに失敗しました。時間をおいてもう一度お試しください。',
                ]);
        }

        if (! $user) {
            $user = User::create([
                'name' => $googleUser->getName() ?? 'Google User',
                'email' => $email,
                'google_id' => $googleId,
                'email_verified_at' => now(),
                'password' => bcrypt(str()->random(32)),
            ]);
        } else {
            $updated = false;

            if (! $user->google_id) {
                $user->google_id = $googleId;
                $updated = true;
            }

            if (! $user->email_verified_at) {
                $user->email_verified_at = now();
                $updated = true;
            }

            if ($updated) {
                $user->save();
            }
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $this->loginSecurity->recordSuccessfulLogin($user, $request, 'Googleログイン');

        return redirect()->route((bool) config('furimadeck.cutover_enabled') ? 'furimadeck-dashboard' : 'dashboard');
    }

    private function hasVerifiedGoogleEmail(SocialiteUser $googleUser): bool
    {
        $rawUser = $googleUser->getRaw();
        $verified = $rawUser['email_verified'] ?? $rawUser['verified_email'] ?? false;

        return filter_var($verified, FILTER_VALIDATE_BOOLEAN);
    }

    private function googleDriver()
    {
        $driver = Socialite::driver('google');

        if ((bool) config('services.google.direct_connection')) {
            $driver->setHttpClient(new Client(['proxy' => '']));
        }

        return $driver;
    }
}
