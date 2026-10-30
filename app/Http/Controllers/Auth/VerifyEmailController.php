<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyEmailController extends Controller
{
    /** Mark the account from a valid signed link as verified. */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (Auth::check() && Auth::id() !== $user->getKey()) {
            abort(403);
        }

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if (! Auth::check()) {
            return redirect()
                ->route('login')
                ->with('status', 'email-verified-login');
        }

        $destination = (bool) config('furimadeck.cutover_enabled') ? 'furimadeck-dashboard' : 'dashboard';

        return redirect()->intended(route($destination, absolute: false).'?verified=1');
    }
}
