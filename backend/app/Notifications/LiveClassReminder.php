<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LiveClassReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $title, public string $startsAtIso, public string $courseId) {}

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
        $when = \Illuminate\Support\Carbon::parse($this->startsAtIso)->timezone('Africa/Lagos')->format('D j M, H:i').' WAT';

        return (new MailMessage)->subject('Live class starting soon: '.$this->title)->line('"'.$this->title.'" starts at '.$when.'.')
            ->action('Open the class', rtrim(config('app.frontend_url'), '/').'/student/courses/'.$this->courseId.'/live');
    }

    public function toArray(object $n): array
    {
        return ['type' => 'live.reminder', 'title' => 'Live class soon', 'body' => $this->title, 'starts_at' => $this->startsAtIso, 'course_id' => $this->courseId];
    }
}
