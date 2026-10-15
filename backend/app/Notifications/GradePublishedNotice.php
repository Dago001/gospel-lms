<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GradePublishedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $title, public bool $amended = false) {}

    public function via(object $n): array
    {
        return ['mail', 'database'];
    }

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function toMail(object $n): MailMessage
    {
        return (new MailMessage)->subject(($this->amended ? 'Updated result: ' : 'New result: ').$this->title)
            ->line('Your result for "'.$this->title.'" has been '.($this->amended ? 'updated' : 'published').'.')
            ->action('View my results', rtrim(config('app.frontend_url'), '/').'/student/results');
    }

    public function toArray(object $n): array
    {
        return ['type' => 'grade.published', 'title' => $this->amended ? 'Result updated' : 'New result', 'body' => $this->title];
    }
}
