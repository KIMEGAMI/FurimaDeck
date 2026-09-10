<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly EmailVerificationDelivery $emailVerificationDelivery) {}

    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'terms_accepted' => ['accepted'],
            'privacy_accepted' => ['accepted'],
        ]);

        $email = Str::lower((string) $request->email);
        $existingUser = User::where('email', $email)->first();

        if ($existingUser instanceof User) {
            return $this->handleExistingRegistrationAttempt($request, $existingUser);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $email,
            'password' => Hash::make($request->password),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        if (! $this->emailVerificationDelivery->send($user, 'registration')) {
            return $this->redirectToVerificationNoticeWithMailWarning();
        }

        return redirect()->route('verification.notice');
    }

    private function redirectToVerificationNoticeWithMailWarning(): RedirectResponse
    {
        return redirect()
            ->route('verification.notice')
            ->with('status', EmailVerificationDelivery::failureStatus());
    }

    private function handleExistingRegistrationAttempt(Request $request, User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail() || ! Hash::check((string) $request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'このメールアドレスは既に登録されています。ログイン画面からログインしてください。',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        if (! $this->emailVerificationDelivery->send($user, 'repeated_registration')) {
            return $this->redirectToVerificationNoticeWithMailWarning();
        }

        return redirect()
            ->route('verification.notice')
            ->with('status', EmailVerificationDelivery::sentStatus());
    }
}
