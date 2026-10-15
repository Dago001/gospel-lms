<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Account-security notice (password change, MFA change, suspension). Never includes secrets. */
class SecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $subject, public string $body) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('GOSPEL LMS: '.$this->subject)
            ->greeting('Hello '.$notifiable->name)
            ->line($this->body)
            ->line('If you did not expect this change, reset your password immediately and contact support.');
    }
}
