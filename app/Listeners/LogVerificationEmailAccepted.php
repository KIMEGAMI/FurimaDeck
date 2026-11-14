<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogVerificationEmailAccepted
{
    public function handle(NotificationSent $event): void
    {
        if (
            $event->channel !== 'mail'
            || ! $event->notification instanceof VerifyEmail
            || ! $event->notifiable instanceof User
            || ! $this->usesResendTransport()
        ) {
            return;
        }

        try {
            $messageId = $event->response?->getMessageId();

            if (! is_string($messageId) || trim($messageId) === '') {
                return;
            }

            Log::info('Email verification accepted by Resend.', [
                'user_id' => $event->notifiable->getKey(),
                'provider_message_id' => $messageId,
            ]);
        } catch (Throwable) {
            // Observability must never turn a provider-accepted email into a failed send.
        }
    }

    private function usesResendTransport(): bool
    {
        if (config('mail.default') === 'resend') {
            return true;
        }

        return config('mail.default') === 'smtp'
            && strtolower(trim((string) config('mail.mailers.smtp.host'))) === 'smtp.resend.com';
    }
}
