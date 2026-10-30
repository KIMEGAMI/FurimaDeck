<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EmailVerificationNotificationController extends Controller
{
    public function __construct(private readonly EmailVerificationDelivery $emailVerificationDelivery) {}

    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $guestRequest = $user === null;

        if ($guestRequest) {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string'],
            ]);

            $guard = Auth::guard();
            $credentials = [
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
            ];

            if ($guard->validate($credentials)) {
                $candidate = $guard->getLastAttempted();
                if ($candidate instanceof User && ! $candidate->hasVerifiedEmail()) {
                    $user = $candidate;
                }
            }

            if ($user === null) {
                return back()->with('status', 'verification-link-requested');
            }
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        $sent = $this->emailVerificationDelivery->send($user, 'verification_resend');

        if ($guestRequest) {
            return back()->with('status', 'verification-link-requested');
        }

        if (! $sent) {
            return back()->with('status', EmailVerificationDelivery::failureStatus());
        }

        return back()->with('status', EmailVerificationDelivery::sentStatus());
    }
}
