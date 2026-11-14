<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class EmailVerificationDelivery
{
    private const SUPPORTED_MAILERS = ['smtp', 'resend'];

    public function send(User $user, string $context): bool
    {
        $mailer = config('mail.default');
        if (! is_string($mailer) || ! in_array($mailer, self::SUPPORTED_MAILERS, true)) {
            Log::warning('Email verification notification delivery is not configured for external delivery.', [
                'context' => $context,
                'mailer' => is_string($mailer) ? $mailer : 'invalid',
            ]);

            return false;
        }

        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $exception) {
            $diagnostics = [
                'user_id' => $user->id,
                'context' => $context,
                'error_class' => $exception::class,
            ];

            $responseCode = $exception instanceof TransportExceptionInterface ? $exception->getCode() : 0;
            if ($responseCode >= 400 && $responseCode <= 599) {
                $diagnostics['transport_response_code'] = $responseCode;
            }

            Log::warning('Email verification notification delivery failed.', $diagnostics);

            return false;
        }
    }

    public static function failureStatus(): string
    {
        return 'verification-link-send-failed';
    }

    public static function sentStatus(): string
    {
        return 'verification-link-sent';
    }
}
