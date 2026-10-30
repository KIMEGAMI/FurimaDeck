<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => Str::lower((string) $this->input('email')),
            'password' => (string) $this->input('password'),
        ];

        $guard = Auth::guard();
        $credentialsAreValid = $guard->validate($credentials);
        $user = $guard->getLastAttempted();

        if (! $credentialsAreValid || ! $user?->hasVerifiedEmail()) {
            $this->rejectAuthentication();
        }

        if (! $guard->attempt($credentials, $this->boolean('remember'))) {
            $this->rejectAuthentication();
        }

        if (! Auth::user()?->hasVerifiedEmail()) {
            Auth::logout();
            $this->rejectAuthentication();
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->ipThrottleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (
            ! RateLimiter::tooManyAttempts($this->throttleKey(), $this->maxAttempts())
            && ! RateLimiter::tooManyAttempts($this->ipThrottleKey(), $this->ipMaxAttempts())
        ) {
            return;
        }

        event(new Lockout($this));

        $seconds = max(
            RateLimiter::availableIn($this->throttleKey()),
            RateLimiter::availableIn($this->ipThrottleKey())
        );

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }

    private function ipThrottleKey(): string
    {
        return 'login-ip|'.$this->ip();
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('auth_security.login_max_attempts', 5));
    }

    private function ipMaxAttempts(): int
    {
        return max(1, (int) config('auth_security.login_ip_max_attempts', 20));
    }

    private function decaySeconds(): int
    {
        return max(1, (int) config('auth_security.login_decay_seconds', 60));
    }

    private function rejectAuthentication(): never
    {
        RateLimiter::hit($this->throttleKey(), $this->decaySeconds());
        RateLimiter::hit($this->ipThrottleKey(), $this->decaySeconds());

        throw ValidationException::withMessages([
            'email' => trans('auth.failed'),
        ]);
    }
}
