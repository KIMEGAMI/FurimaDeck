<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    public function __construct(private readonly EmailVerificationDelivery $emailVerificationDelivery) {}

    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        if (! $this->emailVerificationDelivery->send($request->user(), 'verification_resend')) {
            return back()->with('status', EmailVerificationDelivery::failureStatus());
        }

        return back()->with('status', EmailVerificationDelivery::sentStatus());
    }
}
