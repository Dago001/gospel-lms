<?php

namespace App\Services\Video;

use App\Events\LessonCompleted;
use App\Exceptions\DomainException;
use App\Models\Lesson;
use App\Models\LessonCompletionRecord;
use App\Models\LessonProgress;
use App\Models\PlaybackSession;
use App\Models\User;
use App\Models\VideoProgressEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative playback accounting.
 *
 * The client only *reports* positions. Credit is granted for the stretch of media between two reports if and only if
 * the claimed advance is plausible for the elapsed (client-reported) time at <= max playback rate AND the session's total
 * credit never exceeds the server's own wall-clock since session start. Skipped (seeked-over) regions earn nothing, and
 * replaying a region never double counts: completion is "% of the media actually covered", not "reached the end".
 */
class ProgressTracker
{
    public function __construct(private ProgressionService $progression) {}

    public function start(User $user, Lesson $lesson, Request $request): array
    {
        $this->assertTrackable($lesson);
        $progress = LessonProgress::firstOrNew(['user_id' => $user->id, 'lesson_id' => $lesson->id]);
        $resume = $progress->exists && $progress->status !== 'completed' ? min($progress->last_position_seconds, max(0, ($lesson->duration_seconds ?? 0) - 5)) : 0;
        $session = PlaybackSession::create([
            'user_id' => $user->id, 'lesson_id' => $lesson->id, 'state' => 'stopped', 'last_position' => $resume,
            'started_at' => now(), 'expires_at' => now()->addHours(config('video.session_max_hours')), 'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return ['session' => $session, 'resume_position' => $resume, 'progress' => $this->progressPayload($progress)];
    }

    /**
     * @param  array<int,array{seq:int,type:string,position:float|int,client_ts_ms:int|null}>  $events
     * @return array{results:array<int,array>, progress:array, completed:bool}
     */
    public function ingest(PlaybackSession $session, array $events): array
    {
        return DB::transaction(function () use ($session, $events) {
            $s = PlaybackSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $s->isUsable()) {
                throw new DomainException('This playback session has expired. Reload the lesson to continue.', 'session_expired', 410);
            }
            $lesson = Lesson::with('course')->findOrFail($s->lesson_id);
            $duration = (float) ($lesson->duration_seconds ?? 0);
            $progress = LessonProgress::firstOrCreate(['user_id' => $s->user_id, 'lesson_id' => $lesson->id], ['course_id' => $lesson->course_id, 'status' => 'in_progress', 'covered_ranges' => []]);
            $ranges = $progress->covered_ranges ?? [];
            $results = [];
            usort($events, fn ($a, $b) => $a['seq'] <=> $b['seq']);

            foreach ($events as $e) {
                if ($existing = VideoProgressEvent::where(['session_id' => $s->id, 'seq' => $e['seq']])->first()) {
                    $results[] = ['seq' => $e['seq'], 'accepted' => $existing->accepted, 'reason' => $existing->reject_reason, 'duplicate' => true];   // idempotent replay
                    continue;
                }
                [$ok, $reason, $credit] = $this->apply($s, $e, $duration, $ranges);
                VideoProgressEvent::create(['session_id' => $s->id, 'seq' => $e['seq'], 'type' => $e['type'], 'position' => $e['position'], 'client_ts_ms' => $e['client_ts_ms'] ?? null,
                    'accepted' => $ok, 'reject_reason' => $reason, 'credited_seconds' => $credit]);
                $results[] = ['seq' => $e['seq'], 'accepted' => $ok, 'reason' => $reason];
                $s->last_seq = max($s->last_seq, $e['seq']);
            }
            $s->last_event_at = now();
            $s->save();

            $covered = array_sum(array_map(fn ($r) => $r[1] - $r[0], $ranges));
            $percent = $duration > 0 ? min(100, round($covered / $duration * 100, 2)) : 0;
            $progress->fill(['covered_ranges' => $ranges, 'covered_seconds' => $covered, 'percent' => $percent, 'last_position_seconds' => $s->last_position]);
            $completed = false;
            if ($progress->status !== 'completed' && $duration > 0 && $percent >= $lesson->requiredCompletionPercent()) {
                $progress->fill(['status' => 'completed', 'completed_at' => now(), 'last_position_seconds' => 0]);
                $completed = $this->recordCompletion($s->user_id, $lesson, 'video', $percent, $s->id);
            }
            $progress->save();

            return ['results' => $results, 'progress' => $this->progressPayload($progress), 'completed' => $completed];
        });
    }

    /** @return array{0:bool,1:?string,2:float} accepted, reject reason, seconds credited */
    private function apply(PlaybackSession $s, array $e, float $duration, array &$ranges): array
    {
        $tol = config('video.position_tolerance_seconds');
        $rate = config('video.max_playback_rate');
        $pos = (float) $e['position'];
        $cts = $e['client_ts_ms'] ?? null;
        if (! is_finite($pos) || $pos < 0 || ($duration > 0 && $pos > $duration + $tol)) {
            return [false, 'position_out_of_range', 0.0];
        }
        $pos = $duration > 0 ? min($pos, $duration) : $pos;

        switch ($e['type']) {
            case 'seek':        // user jumped: re-baseline, no credit for the skipped region
                $s->last_position = $pos;
                $s->last_client_ts_ms = $cts;

                return [true, null, 0.0];
            case 'play':
                $s->state = 'playing';
                $s->last_position = $pos;
                $s->last_client_ts_ms = $cts;

                return [true, null, 0.0];
            case 'heartbeat':
            case 'pause':
            case 'ended':
                $credit = 0.0;
                if ($s->state === 'playing' && $s->last_client_ts_ms !== null && $cts !== null) {
                    $dt = ($cts - $s->last_client_ts_ms) / 1000;
                    $delta = $pos - $s->last_position;
                    if ($dt < 0 || $dt > config('video.max_event_gap_seconds')) {
                        return [false, 'bad_timing', 0.0];
                    }
                    if ($delta > $dt * $rate + $tol) {
                        return [false, 'implausible_advance', 0.0];          // a jump presented as normal playback
                    }
                    if ($delta > 0) {
                        $wall = $s->started_at->diffInMilliseconds(now(), true) / 1000;
                        if ($s->credited_seconds + $delta > $wall * $rate + 5) {
                            return [false, 'exceeds_wall_clock', 0.0];         // more playback than real time allows
                        }
                        $this->addRange($ranges, $s->last_position, $pos);
                        $s->credited_seconds += $delta;
                        $credit = $delta;
                    }
                }
                $s->last_position = $pos;
                $s->last_client_ts_ms = $cts;
                if ($e['type'] !== 'heartbeat') {
                    $s->state = 'stopped';
                }
                if ($e['type'] === 'ended') {
                    $s->ended_at = null;       // session may continue (replay); ended is just a player state
                }

                return [true, null, $credit];
        }

        return [false, 'unknown_type', 0.0];
    }

    /** Insert [a,b] and merge overlaps so replays never double count. */
    private function addRange(array &$ranges, float $a, float $b): void
    {
        if ($b <= $a) {
            return;
        }
        $ranges[] = [round($a, 2), round($b, 2)];
        usort($ranges, fn ($x, $y) => $x[0] <=> $y[0]);
        $merged = [];
        foreach ($ranges as $r) {
            if ($merged && $r[0] <= end($merged)[1] + 0.5) {          // 0.5s join tolerance between heartbeats
                $merged[count($merged) - 1][1] = max(end($merged)[1], $r[1]);
            } else {
                $merged[] = $r;
            }
        }
        $ranges = $merged;
    }

    /** Non-video lessons: reading/acknowledgement. Video lessons can only complete through verified playback. */
    public function completeReading(User $user, Lesson $lesson): bool
    {
        if ($lesson->type === 'video' || $lesson->type === 'live') {
            throw new DomainException('This lesson completes through '.($lesson->type === 'video' ? 'watching the video.' : 'attending the live class.'), 'completion_not_manual', 403);
        }
        $pending = $lesson->resources()->where('requires_acknowledgement', true)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('resource_acknowledgements')->whereColumn('resource_acknowledgements.resource_id', 'lesson_resources.id')->where('user_id', $user->id))->count();
        if ($pending > 0) {
            throw new DomainException('Acknowledge the required study materials first.', 'acknowledgement_required', 422, ['pending' => $pending]);
        }

        return DB::transaction(function () use ($user, $lesson) {
            $p = LessonProgress::firstOrCreate(['user_id' => $user->id, 'lesson_id' => $lesson->id], ['course_id' => $lesson->course_id, 'status' => 'in_progress', 'covered_ranges' => []]);
            if ($p->status === 'completed') {
                return false;
            }
            $p->update(['status' => 'completed', 'completed_at' => now(), 'percent' => 100]);

            return $this->recordCompletion($user->id, $lesson, 'reading', 100, null);
        });
    }

    private function recordCompletion(string $userId, Lesson $lesson, string $method, float $percent, ?string $sessionId): bool
    {
        $created = LessonCompletionRecord::firstOrCreate(['user_id' => $userId, 'lesson_id' => $lesson->id],
            ['course_id' => $lesson->course_id, 'method' => $method, 'percent_at_completion' => $percent, 'playback_session_id' => $sessionId, 'completed_at' => now()]);
        if ($created->wasRecentlyCreated) {
            DB::afterCommit(fn () => event(new LessonCompleted($userId, $lesson->id, $lesson->course_id)));
        }

        return $created->wasRecentlyCreated;
    }

    private function assertTrackable(Lesson $lesson): void
    {
        if ($lesson->type !== 'video' || ! $lesson->video_source) {
            throw new DomainException('This lesson has no video.', 'not_a_video', 422);
        }
        if (! $lesson->duration_seconds) {
            throw new DomainException('This video is not ready yet.', 'video_not_ready', 409);
        }
    }

    private function progressPayload(LessonProgress $p): array
    {
        return ['status' => $p->status ?? 'in_progress', 'percent' => (float) ($p->percent ?? 0), 'covered_seconds' => (float) ($p->covered_seconds ?? 0), 'last_position_seconds' => (float) ($p->last_position_seconds ?? 0)];
    }
}
