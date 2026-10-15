<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Notifications\AnnouncementNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fan-out in chunks. notified_at is claimed atomically so a retried job never double-sends. */
class SendAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $announcementId) {}

    public function handle(): void
    {
        $claimed = Announcement::whereKey($this->announcementId)->whereNull('notified_at')->where('notify', true)->update(['notified_at' => now()]);
        if (! $claimed) {
            return;
        }
        $a = Announcement::with('author')->findOrFail($this->announcementId);
        $course = $a->course_id ? \App\Models\Course::find($a->course_id) : null;
        $text = trim(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $a->body)));
        $notice = fn () => new AnnouncementNotice($a->title, $text, $course?->title);

        $users = $a->course_id
            ? User::whereIn('id', CourseEnrollment::where('course_id', $a->course_id)->whereIn('status', ['active', 'completed'])->select('user_id'))
            : User::where('status', 'active')->when($a->audience === 'students', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('slug', 'student')))
                ->when($a->audience === 'lecturers', fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('slug', 'lecturer')));
        $users->where('status', 'active')->chunkById(200, fn ($chunk) => $chunk->each(fn ($u) => $u->notify($notice())));
    }
}
