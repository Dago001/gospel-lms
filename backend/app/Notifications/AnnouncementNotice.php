<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class AnnouncementNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $title, public string $bodyText, public ?string $courseTitle = null) {}

    public function via(object $n): array
    {
        return ['mail', 'database'];
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function toMail(object $n): MailMessage
    {
        return (new MailMessage)->subject(($this->courseTitle ? $this->courseTitle.': ' : '').$this->title)->line(Str::limit($this->bodyText, 400))
            ->action('Read more', rtrim(config('app.frontend_url'), '/').'/notifications');
    }

    public function toArray(object $n): array
    {
        return ['type' => 'announcement', 'title' => $this->title, 'body' => Str::limit($this->bodyText, 200), 'course' => $this->courseTitle];
    }
}
