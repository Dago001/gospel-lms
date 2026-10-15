<?php

namespace App\Services\Live;

use App\Exceptions\DomainException;
use App\Jobs\EvaluateCourseCompletion;
use App\Models\Course;
use App\Models\LiveSession;
use App\Models\LiveSessionAttendance;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnrolmentService;
use App\Services\Payments\GatewayException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LiveSessionService
{
    public const JOIN_WINDOW_BEFORE_MINUTES = 15;

    public function __construct(private ZoomClient $zoom, private EnrolmentService $enrolments, private AuditLogger $audit) {}

    public function create(Course $course, User $by, array $d): LiveSession
    {
        $session = new LiveSession(['title' => $d['title'], 'description' => $d['description'] ?? null, 'starts_at' => $d['starts_at'], 'duration_minutes' => $d['duration_minutes'],
            'provider' => $d['provider'], 'lesson_id' => $d['lesson_id'] ?? null, 'counts_for_attendance' => $d['counts_for_attendance'] ?? true, 'min_minutes_for_attendance' => $d['min_minutes_for_attendance'] ?? 0]);
        $session->course_id = $course->id;
        $session->created_by = $by->id;
        if ($d['provider'] === 'zoom') {
            try {
                $m = $this->zoom->createMeeting($d['title'], $session->starts_at->utc(), $d['duration_minutes']);
            } catch (GatewayException $e) {
                throw new DomainException($e->retryable ? 'Zoom is unreachable right now. Try again shortly.' : 'Zoom could not create the meeting: '.$e->getMessage(), 'zoom_unavailable', 503);
            }
            $session->forceFill(['provider_meeting_id' => $m['id'], 'join_url' => $m['join_url'], 'host_url' => $m['start_url']]);
        } else {
            $session->join_url = $d['join_url'];
        }
        $session->save();
        $this->audit->log('live_session.created', $session, new: ['title' => $session->title, 'starts_at' => $session->starts_at->toIso8601String()]);

        return $session;
    }

    public function reschedule(LiveSession $s, array $d): LiveSession
    {
        if (in_array($s->status, ['ended', 'cancelled'], true)) {
            throw new DomainException('This session can no longer be changed.', 'session_closed', 409);
        }
        $s->fill(array_intersect_key($d, array_flip(['title', 'description', 'starts_at', 'duration_minutes', 'counts_for_attendance', 'min_minutes_for_attendance'])));
        if ($s->provider === 'zoom' && $s->provider_meeting_id && $s->isDirty(['title', 'starts_at', 'duration_minutes'])) {
            try {
                $this->zoom->updateMeeting($s->provider_meeting_id, $s->title, $s->starts_at->utc(), $s->duration_minutes);
            } catch (GatewayException) {
                throw new DomainException('Zoom could not update the meeting. Nothing was changed.', 'zoom_unavailable', 503);
            }
        }
        $s->save();
        $this->audit->log('live_session.updated', $s, new: array_keys($d));

        return $s;
    }

    public function cancel(LiveSession $s): void
    {
        if ($s->provider === 'zoom' && $s->provider_meeting_id) {
            try {
                $this->zoom->deleteMeeting($s->provider_meeting_id);
            } catch (GatewayException) {
                throw new DomainException('Zoom could not cancel the meeting. Try again.', 'zoom_unavailable', 503);
            }
        }
        $s->update(['status' => 'cancelled']);
        $this->audit->log('live_session.cancelled', $s);
    }

    /** Returns this learner's own join link. Only enrolled learners, only near the start, and each gets their own Zoom registrant link. */
    public function joinLink(User $user, LiveSession $s): string
    {
        if (! $this->enrolments->userHasAccess($user, $s->course)) {
            throw new DomainException('You are not enrolled in this course.', 'not_enrolled', 403);
        }
        if ($s->status === 'cancelled') {
            throw new DomainException('This session was cancelled.', 'cancelled', 409);
        }
        if (now()->lt($s->starts_at->copy()->subMinutes(self::JOIN_WINDOW_BEFORE_MINUTES)) || now()->gt($s->endsAt()->addMinutes(30))) {
            throw new DomainException('This session is not open for joining.', 'not_joinable', 409);
        }
        if ($s->provider !== 'zoom') {
            return (string) $s->join_url;
        }
        $row = LiveSessionAttendance::firstOrCreate(['live_session_id' => $s->id, 'user_id' => $user->id], ['source' => 'zoom', 'minutes_attended' => 0, 'present' => false]);
        if (! $row->join_url) {
            [$first, $last] = array_pad(explode(' ', $user->name, 2), 2, '');
            try {
                $reg = $this->zoom->registrant($s->provider_meeting_id, $user->email, $first, $last);
            } catch (GatewayException) {
                throw new DomainException('Could not prepare your join link. Please try again.', 'zoom_unavailable', 503);
            }
            $row->update(['registrant_id' => $reg['id'], 'join_url' => $reg['join_url']]);
        }

        return $row->join_url;
    }

    /**
     * Pull Zoom's report and upsert attendance (set, never increment, so replays are harmless). Manual overrides are respected.
     */
    public function syncAttendance(LiveSession $s): int
    {
        $minutes = $this->zoom->participantMinutes($s->provider_meeting_id);
        $need = $s->requiredMinutes();
        $n = 0;
        $users = User::whereIn(DB::raw('lower(email)'), array_keys($minutes))->get()->keyBy(fn ($u) => strtolower($u->email));
        foreach ($minutes as $email => $mins) {
            $u = $users[$email] ?? null;
            if (! $u || ! $this->enrolments->userHasAccess($u, $s->course)) {
                continue;                                      // guests and non-students never receive attendance
            }
            $row = LiveSessionAttendance::firstOrNew(['live_session_id' => $s->id, 'user_id' => $u->id]);
            if ($row->source === 'manual' && $row->exists) {
                continue;                                      // a lecturer's decision beats the automatic report
            }
            $row->fill(['source' => 'zoom', 'minutes_attended' => $mins, 'present' => $mins >= $need])->save();
            EvaluateCourseCompletion::dispatch($u->id, $s->course_id, 'attendance');
            $n++;
        }
        $s->update(['attendance_synced_at' => now()]);

        return $n;
    }

    public function setAttendance(LiveSession $s, User $student, bool $present, ?int $minutes, ?string $note, User $by): LiveSessionAttendance
    {
        if (! $this->enrolments->userHasAccess($student, $s->course)) {
            throw new DomainException('That learner is not enrolled in this course.', 'not_enrolled', 422);
        }
        $row = LiveSessionAttendance::firstOrNew(['live_session_id' => $s->id, 'user_id' => $student->id]);
        $old = $row->exists ? $row->only(['present', 'minutes_attended']) : null;
        $row->fill(['present' => $present, 'minutes_attended' => $minutes ?? ($present ? $s->requiredMinutes() : 0), 'source' => 'manual', 'note' => $note, 'recorded_by' => $by->id])->save();
        $this->audit->log('attendance.set', $row, $old, $row->only(['present', 'minutes_attended']), $note, $by->id);
        EvaluateCourseCompletion::dispatch($student->id, $s->course_id, 'attendance');

        return $row;
    }

    public function logFailure(LiveSession $s, \Throwable $e): void
    {
        Log::warning('live.attendance_sync_failed', ['session' => $s->id, 'why' => $e->getMessage()]);
    }
}
