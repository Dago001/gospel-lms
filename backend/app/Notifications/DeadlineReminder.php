<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeadlineReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $title, public string $dueIso, public string $kind) {}

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
        $when = \Illuminate\Support\Carbon::parse($this->dueIso)->timezone('Africa/Lagos')->format('D j M, H:i').' WAT';

        return (new MailMessage)->subject('Due soon: '.$this->title)->line('"'.$this->title.'" '.($this->kind === 'assessment' ? 'closes' : 'is due').' at '.$when.'.')
            ->action('Open my courses', rtrim(config('app.frontend_url'), '/').'/student/courses');
    }

    public function toArray(object $n): array
    {
        return ['type' => 'deadline.reminder', 'title' => 'Deadline approaching', 'body' => $this->title, 'due_at' => $this->dueIso];
    }
}
