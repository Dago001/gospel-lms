<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ForumReplyNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $threadTitle, public string $replier, public string $courseId, public string $threadId) {}

    public function via(object $n): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $n): MailMessage
    {
        return (new MailMessage)->subject('New reply: '.$this->threadTitle)->line("{$this->replier} replied to your discussion \"{$this->threadTitle}\".")
            ->action('Read the reply', rtrim(config('app.frontend_url'), '/')."/student/courses/{$this->courseId}/discussions/{$this->threadId}");
    }

    public function toArray(object $n): array
    {
        return ['type' => 'forum.reply', 'title' => 'New reply', 'body' => $this->threadTitle, 'thread_id' => $this->threadId, 'course_id' => $this->courseId];
    }
}
