<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\LoginSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly LoginSecurityService $loginSecurity) {}

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $this->loginSecurity->recordSuccessfulLogin($request->user(), $request, 'メールログイン');

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended($this->authenticatedDestination());
    }

    public function demo(Request $request): RedirectResponse
    {
        $email = config('demo.user_email');
        $password = config('demo.user_password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw ValidationException::withMessages([
                'email' => 'デモユーザーが設定されていません。管理者にお問い合わせください。',
            ]);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            throw ValidationException::withMessages([
                'email' => 'デモユーザーにログインできませんでした。管理者にお問い合わせください。',
            ]);
        }

        $request->session()->regenerate();
        $this->loginSecurity->recordSuccessfulLogin($request->user(), $request, 'デモログイン');

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        return redirect()->intended($this->authenticatedDestination());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function authenticatedDestination(): string
    {
        return (bool) config('furimadeck.cutover_enabled')
            ? route('furimadeck-dashboard', absolute: false)
            : route('dashboard', absolute: false);
    }
}
