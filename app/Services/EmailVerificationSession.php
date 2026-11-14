<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class EmailVerificationSession
{
    public const PENDING_USER_ID = 'email_verification_pending_user_id';

    public function remember(Request $request, User $user): void
    {
        $request->session()->put(self::PENDING_USER_ID, (int) $user->getKey());
    }

    public function consumeMatchingUser(Request $request, User $user): bool
    {
        $pendingUserId = $request->session()->get(self::PENDING_USER_ID);
        $matchesUser = (is_int($pendingUserId) || (is_string($pendingUserId) && ctype_digit($pendingUserId)))
            && (int) $pendingUserId === (int) $user->getKey();

        if ($matchesUser) {
            $this->clear($request);
        }

        return $matchesUser;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget(self::PENDING_USER_ID);
    }
}
