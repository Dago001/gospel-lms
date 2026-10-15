<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

class LogNotificationDelivery
{
    public function handle(NotificationSent|NotificationFailed $e): void
    {
        $failed = $e instanceof NotificationFailed;
        NotificationLog::create(['user_id' => $e->notifiable instanceof User ? $e->notifiable->id : null, 'notification' => class_basename($e->notification), 'channel' => (string) $e->channel,
            'status' => $failed ? 'failed' : 'sent', 'error' => $failed ? mb_substr(json_encode($e->data), 0, 500) : null]);
    }
}
