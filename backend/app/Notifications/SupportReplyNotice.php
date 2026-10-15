<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SupportReplyNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $ticketNumber, public string $subject, public string $preview) {}

    public function via(object $n): array
    {
        return $n instanceof \App\Models\User ? ['mail', 'database'] : ['mail'];   // guests (on-demand route) only get email
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function toMail(object $n): MailMessage
    {
        return (new MailMessage)->subject("[{$this->ticketNumber}] ".$this->subject)->line('Support has replied to your request:')->line(Str::limit($this->preview, 500))
            ->line('Reply to this request from your account at '.rtrim(config('app.frontend_url'), '/').'/support. Reference: '.$this->ticketNumber.'.');
    }

    public function toArray(object $n): array
    {
        return ['type' => 'support.reply', 'title' => 'Support replied', 'body' => $this->subject, 'ticket' => $this->ticketNumber];
    }
}
