<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CourseCompletedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $courseTitle) {}

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
        return (new MailMessage)->subject('You have completed '.$this->courseTitle)->line('Congratulations. You have met every requirement for "'.$this->courseTitle.'".')
            ->action('View my courses', rtrim(config('app.frontend_url'), '/').'/student/courses');
    }

    public function toArray(object $n): array
    {
        return ['type' => 'course.completed', 'title' => 'Course completed', 'body' => $this->courseTitle];
    }
}
