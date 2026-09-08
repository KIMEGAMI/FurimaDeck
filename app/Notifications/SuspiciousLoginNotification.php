<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuspiciousLoginNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $loginMethod,
        private readonly string $ipAddress,
        private readonly string $loggedInAt,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('FurimaDeck 不審なログインの可能性があります')
            ->greeting('FurimaDeckをご利用いただきありがとうございます。')
            ->line('普段と異なる環境からログインされた可能性があります。')
            ->line('ログイン方法: '.$this->loginMethod)
            ->line('ログイン日時: '.$this->loggedInAt)
            ->line('IPアドレス: '.$this->ipAddress)
            ->line('心当たりがない場合は、すぐにパスワードを変更してください。')
            ->action('パスワードを変更する', route('profile.edit'))
            ->line('このログインに心当たりがある場合、対応は不要です。');
    }
}
