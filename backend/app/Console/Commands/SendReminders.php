<?php

namespace App\Console\Commands;

use App\Models\Assessment;
use App\Models\Assignment;
use App\Models\CourseEnrollment;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\LiveClassReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Notify learners about live classes (~1h ahead) and deadlines (~24h ahead). Each reminder is sent once per learner.';

    public function handle(): int
    {
        $sent = 0;
        foreach (LiveSession::where('status', 'scheduled')->whereBetween('starts_at', [now()->addMinutes(45), now()->addMinutes(75)])->get() as $s) {
            $sent += $this->fanOut($s->course_id, 'live_class', $s->id, fn () => new LiveClassReminder($s->title, $s->starts_at->toIso8601String(), $s->course_id));
            $s->update(['reminded_at' => now()]);
        }
        foreach (Assignment::where('status', 'published')->whereBetween('due_at', [now()->addHours(20), now()->addHours(28)])->get() as $a) {
            $sent += $this->fanOut($a->course_id, 'assignment_due', $a->id, fn () => new DeadlineReminder($a->title, $a->due_at->toIso8601String(), 'assignment'),
                fn ($uid) => ! $a->submissions()->where('user_id', $uid)->exists());
        }
        foreach (Assessment::where('status', 'published')->whereBetween('closes_at', [now()->addHours(20), now()->addHours(28)])->get() as $a) {
            $sent += $this->fanOut($a->course_id, 'assessment_closing', $a->id, fn () => new DeadlineReminder($a->title, $a->closes_at->toIso8601String(), 'assessment'),
                fn ($uid) => ! $a->attempts()->where('user_id', $uid)->exists());
        }
        $this->info("reminders sent: $sent");

        return self::SUCCESS;
    }

    private function fanOut(string $courseId, string $kind, string $subjectId, callable $make, ?callable $eligible = null): int
    {
        $n = 0;
        CourseEnrollment::where('course_id', $courseId)->where('status', 'active')->pluck('user_id')->each(function ($uid) use ($kind, $subjectId, $make, $eligible, &$n) {
            if ($eligible && ! $eligible($uid)) {
                return;
            }
            $claimed = DB::table('sent_reminders')->insertOrIgnore(['id' => (string) Str::uuid(), 'user_id' => $uid, 'kind' => $kind, 'subject_id' => $subjectId, 'sent_at' => now()]);
            if ($claimed && ($u = User::find($uid))) {     // the unique row is the lock: concurrent runs cannot double-send
                $u->notify($make());
                $n++;
            }
        });

        return $n;
    }
}
