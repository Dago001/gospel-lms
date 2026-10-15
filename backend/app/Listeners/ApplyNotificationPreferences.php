<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications as N;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Per-user channel preferences. Security and payment notices are mandatory and never pass through here.
 */
class ApplyNotificationPreferences
{
    /** notification class => preference key shown in the UI */
    public const TYPES = [
        N\GradePublishedNotice::class => 'grades', N\AssessmentResultNotice::class => 'grades',
        N\LiveClassReminder::class => 'reminders', N\DeadlineReminder::class => 'reminders',
        N\AnnouncementNotice::class => 'announcements', N\SupportReplyNotice::class => 'support',
        N\CourseCompletedNotice::class => 'completion', N\CertificateIssuedNotice::class => 'completion',
        N\ForumReplyNotice::class => 'forum',
    ];

    public static function keys(): array
    {
        return array_values(array_unique(self::TYPES));
    }

    public function handle(NotificationSending $e): ?bool
    {
        $key = self::TYPES[get_class($e->notification)] ?? null;
        $col = ['mail' => 'email', 'database' => 'in_app'][$e->channel] ?? null;
        if (! $key || ! $col || ! $e->notifiable instanceof User) {
            return null;
        }
        $pref = NotificationPreference::where(['user_id' => $e->notifiable->id, 'type' => $key])->first();
        if ($pref && ! $pref->{$col}) {
            NotificationLog::create(['user_id' => $e->notifiable->id, 'notification' => class_basename($e->notification), 'channel' => $e->channel, 'status' => 'suppressed']);

            return false;                  // cancels only this channel
        }

        return null;
    }
}
