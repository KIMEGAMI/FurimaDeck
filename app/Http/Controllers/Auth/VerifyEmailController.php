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
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->guest(route('login'));
        }

        $user = User::findOrFail($id);

        if (Auth::id() !== $user->getKey()) {
            abort(403);
        }

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $destination = (bool) config('furimadeck.cutover_enabled') ? 'furimadeck-dashboard' : 'dashboard';

        return redirect()->intended(route($destination, absolute: false).'?verified=1');
    }
}
