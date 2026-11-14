<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationSession;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    /** Mark the account from a valid signed link as verified. */
    public function __invoke(Request $request, string $id, string $hash, EmailVerificationSession $verificationSession): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (Auth::check() && Auth::id() !== $user->getKey()) {
            abort(403);
        }

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        $mayStartSession = ! Auth::check() && $verificationSession->consumeMatchingUser($request, $user);
        if (Auth::check()) {
            $verificationSession->clear($request);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($mayStartSession) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        if (! Auth::check()) {
            return redirect()->route('login')->with('status', 'email-verified-login');
        }

        $destination = (bool) config('furimadeck.cutover_enabled') ? 'furimadeck-dashboard' : 'dashboard';

        return redirect()->intended(route($destination, absolute: false).'?verified=1');
    }
}
