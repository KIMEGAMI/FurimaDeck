<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower((string) $request->email);

        try {
            $status = Password::sendResetLink(
                ['email' => $email]
            );
        } catch (Throwable $exception) {
            Log::warning('Password reset link delivery failed.', [
                'error_class' => $exception::class,
            ]);

            return back()
                ->withInput(['email' => $email])
                ->withErrors([
                    'email' => 'メール送信に失敗しました。管理者はメール設定を確認してください。',
                ]);
        }

        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', '該当するアカウントがある場合は、パスワード再設定用メールを送信します。受信トレイをご確認ください。');
        }

        return back()->withInput(['email' => $email])
            ->withErrors(['email' => __($status)]);
    }
}
