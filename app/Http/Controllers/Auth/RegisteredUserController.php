<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly EmailVerificationDelivery $emailVerificationDelivery) {}

    public function create(): View
    {
        return view('auth.register');
    }

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

        $sent = $this->emailVerificationDelivery->send($user, 'registration');

        return redirect()->route('verification.notice')->with(
            'status',
            $sent ? 'verification-link-requested' : EmailVerificationDelivery::failureStatus(),
        );
    }

    private function handleExistingRegistrationAttempt(Request $request, User $user): RedirectResponse
    {
        if (! $user->hasVerifiedEmail() && Hash::check((string) $request->password, $user->password)) {
            $this->emailVerificationDelivery->send($user, 'repeated_registration');
        }

        return redirect()
            ->route('verification.notice')
            ->with('status', 'verification-link-requested');
    }
}
