<?php

use App\Events\LessonCompleted;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonCompletionRecord;
use App\Models\LessonProgress;
use App\Models\PlaybackSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/** A course with two 600-second YouTube lessons, a student enrolled, signed in. Returns [student, course, l1, l2]. */
function videoCourse($test, array $courseAttrs = []): array
{
    $course = Course::factory()->published()->withCurriculum(2)->create($courseAttrs);
    $lessons = $course->lessons()->orderBy('position')->get();
    foreach ($lessons as $l) {
        $l->forceFill(['type' => 'video', 'video_source' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ', 'duration_seconds' => 600])->save();
    }
    $student = $test->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $test->actingAs($student);

    return [$student, $course, $lessons[0]->fresh(), $lessons[1]->fresh()];
}

function openSession($test, Lesson $lesson): string
{
    return $test->postJson("/api/v1/learn/lessons/{$lesson->id}/playback")->assertCreated()->json('data.session_id');
}

/** Simulates real playback: $secs of media over $secs of wall-clock, heartbeat every 10s. Returns the next seq. */
function watch($test, string $sid, float $from, float $to, int &$seq, int &$clock, float $rate = 1.0): array
{
    $ev = [['seq' => ++$seq, 'type' => 'play', 'position' => $from, 'client_ts_ms' => $clock]];
    for ($p = $from + 10 * $rate; $p < $to; $p += 10 * $rate) {
        $clock += 10_000;
        Carbon::setTestNow(Carbon::now()->addSeconds(10));
        $ev[] = ['seq' => ++$seq, 'type' => 'heartbeat', 'position' => $p, 'client_ts_ms' => $clock];
    }
    $clock += (int) (($to - end($ev)['position']) / $rate * 1000);
    Carbon::setTestNow(Carbon::now()->addSeconds(($to - end($ev)['position']) / $rate));
    $ev[] = ['seq' => ++$seq, 'type' => 'pause', 'position' => $to, 'client_ts_ms' => $clock];

    $out = null;
    $completed = false;
    foreach (array_chunk($ev, 40) as $chunk) {            // the API caps a batch at 50 events
        $out = $test->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => $chunk])->assertOk()->json('data');
        $completed = $completed || $out['completed'];
    }
    $out['completed'] = $completed;

    return $out;
}

afterEach(fn () => Carbon::setTestNow());

it('completes a lesson only after the required share of the video has genuinely been played', function () {
    Event::fake([LessonCompleted::class]);
    [$student, $course, $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $seq = 0; $clock = 1_000_000;

    $r = watch($this, $sid, 0, 300, $seq, $clock);                 // 50%
    expect($r['completed'])->toBeFalse()->and($r['progress']['percent'])->toEqual(50);
    $r = watch($this, $sid, 300, 535, $seq, $clock);               // 89.17% < 90%
    expect($r['completed'])->toBeFalse();
    $r = watch($this, $sid, 535, 545, $seq, $clock);               // 90.83%
    expect($r['completed'])->toBeTrue();
    expect(LessonCompletionRecord::where(['user_id' => $student->id, 'lesson_id' => $l1->id, 'method' => 'video'])->count())->toBe(1);
    Event::assertDispatchedTimes(LessonCompleted::class, 1);
});

it('refuses to credit a seek straight to the end', function () {
    [, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $res = $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [
        ['seq' => 1, 'type' => 'play', 'position' => 0, 'client_ts_ms' => 1000],
        ['seq' => 2, 'type' => 'heartbeat', 'position' => 598, 'client_ts_ms' => 11000],   // claims 598s of media in 10s
        ['seq' => 3, 'type' => 'ended', 'position' => 600, 'client_ts_ms' => 12000],
    ]])->assertOk();
    expect($res->json('data.results.1.accepted'))->toBeFalse()->and($res->json('data.results.1.reason'))->toBe('implausible_advance')
        ->and($res->json('data.completed'))->toBeFalse()->and($res->json('data.progress.percent'))->toEqual(0);
    expect(LessonCompletionRecord::count())->toBe(0);
});

it('does not credit skipped regions when the learner seeks forward legitimately', function () {
    [, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $seq = 0; $clock = 5_000_000;
    watch($this, $sid, 0, 60, $seq, $clock);                       // 60s
    $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [['seq' => ++$seq, 'type' => 'seek', 'position' => 500, 'client_ts_ms' => $clock]]])->assertOk();
    $r = watch($this, $sid, 500, 560, $seq, $clock);               // another 60s
    expect($r['progress']['covered_seconds'])->toEqual(120)->and($r['progress']['percent'])->toEqual(20);
});

it('does not double-count replayed footage', function () {
    [, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $seq = 0; $clock = 9_000_000;
    watch($this, $sid, 0, 100, $seq, $clock);
    $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [['seq' => ++$seq, 'type' => 'seek', 'position' => 0, 'client_ts_ms' => $clock]]]);
    $r = watch($this, $sid, 0, 100, $seq, $clock);
    expect($r['progress']['covered_seconds'])->toEqual(100);
});

it('caps credit at wall-clock time even when each report looks plausible', function () {
    [, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    // Fabricated client timestamps claiming 2x playback for 300s, but only ~10s of real server time passes.
    Carbon::setTestNow(now()->addSeconds(10));
    $ev = [['seq' => 1, 'type' => 'play', 'position' => 0, 'client_ts_ms' => 0]];
    for ($i = 1; $i <= 15; $i++) {
        $ev[] = ['seq' => $i + 1, 'type' => 'heartbeat', 'position' => $i * 20, 'client_ts_ms' => $i * 10_000];   // 20 media-seconds per 10 claimed seconds (2x)
    }
    $r = $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => $ev])->assertOk();
    $reasons = collect($r->json('data.results'))->pluck('reason')->filter()->unique()->all();
    expect($reasons)->toContain('exceeds_wall_clock')->and($r->json('data.progress.covered_seconds'))->toBeLessThan(40);
});

it('is idempotent: replaying the same sequence numbers changes nothing', function () {
    [, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $batch = ['events' => [['seq' => 1, 'type' => 'play', 'position' => 0, 'client_ts_ms' => 0], ['seq' => 2, 'type' => 'heartbeat', 'position' => 10, 'client_ts_ms' => 10_000]]];
    Carbon::setTestNow(now()->addSeconds(10));
    $first = $this->postJson("/api/v1/learn/playback/{$sid}/events", $batch)->json('data.progress.covered_seconds');
    $again = $this->postJson("/api/v1/learn/playback/{$sid}/events", $batch)->assertOk();
    expect($again->json('data.results.1.duplicate'))->toBeTrue()->and($again->json('data.progress.covered_seconds'))->toBe($first);
});

it('rejects out-of-range positions and other users\' sessions', function () {
    [$student, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $r = $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [['seq' => 1, 'type' => 'seek', 'position' => 5000, 'client_ts_ms' => 1]]])->assertOk();
    expect($r->json('data.results.0.reason'))->toBe('position_out_of_range');
    $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [['seq' => 2, 'type' => 'bogus', 'position' => 1]]])->assertUnprocessable();

    $other = $this->makeUser();
    $this->actingAs($other)->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [['seq' => 3, 'type' => 'seek', 'position' => 1, 'client_ts_ms' => 1]]])->assertNotFound();
});

it('expires idle sessions and lets the learner resume from the last verified position', function () {
    [, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $seq = 0; $clock = 1_000;
    watch($this, $sid, 0, 120, $seq, $clock);
    Carbon::setTestNow(now()->addMinutes(20));
    $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [['seq' => 999, 'type' => 'heartbeat', 'position' => 130, 'client_ts_ms' => 5]]])->assertStatus(410)->assertJsonPath('code', 'session_expired');
    $new = $this->postJson("/api/v1/learn/lessons/{$l1->id}/playback")->assertCreated();
    expect($new->json('data.resume_position'))->toEqual(120)->and($new->json('data.progress.percent'))->toEqual(20);   // legitimate progress survives the interruption
});

it('keeps legitimate progress across a network outage (events delivered late in one batch)', function () {
    [, , $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    // Player kept playing offline for 60s of real time; the browser queued 6 heartbeats and flushed them together.
    Carbon::setTestNow(now()->addSeconds(60));
    $ev = [['seq' => 1, 'type' => 'play', 'position' => 0, 'client_ts_ms' => 0]];
    for ($i = 1; $i <= 6; $i++) {
        $ev[] = ['seq' => $i + 1, 'type' => 'heartbeat', 'position' => $i * 10, 'client_ts_ms' => $i * 10_000];
    }
    $r = $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => $ev])->assertOk();
    expect($r->json('data.progress.covered_seconds'))->toEqual(60);
});

it('lets a lecturer complete reading lessons only through the dedicated endpoint, never a video', function () {
    [, $course, $l1] = videoCourse($this);
    $this->postJson("/api/v1/learn/lessons/{$l1->id}/complete")->assertForbidden()->assertJsonPath('code', 'completion_not_manual');
    expect(LessonCompletionRecord::count())->toBe(0);
});

it('requires acknowledged materials before a reading lesson can complete, then completes once', function () {
    $course = Course::factory()->published()->withCurriculum(1)->create();
    $lesson = $course->lessons()->first();
    $res = App\Models\LessonResource::create(['lesson_id' => $lesson->id, 'title' => 'Statement of faith', 'path' => 'x.pdf', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'requires_acknowledgement' => true]);
    $student = $this->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $this->actingAs($student);
    $this->postJson("/api/v1/learn/lessons/{$lesson->id}/complete")->assertUnprocessable()->assertJsonPath('code', 'acknowledgement_required');
    $this->postJson("/api/v1/learn/resources/{$res->id}/acknowledge")->assertOk();
    $this->postJson("/api/v1/learn/lessons/{$lesson->id}/complete")->assertOk()->assertJsonPath('data.newly_completed', true);
    $this->postJson("/api/v1/learn/lessons/{$lesson->id}/complete")->assertOk()->assertJsonPath('data.newly_completed', false);
    expect(LessonCompletionRecord::count())->toBe(1);
});

it('keeps later lessons locked until earlier required lessons are complete, then unlocks the next', function () {
    [$student, $course, $l1, $l2] = videoCourse($this);
    $this->getJson("/api/v1/learn/lessons/{$l2->id}")->assertStatus(423)->assertJsonPath('code', 'lesson_locked');
    $this->postJson("/api/v1/learn/lessons/{$l2->id}/playback")->assertStatus(423);
    $view = $this->getJson("/api/v1/learn/courses/{$course->id}")->assertOk();
    expect($view->json('data.curriculum.0.lessons.1.locked'))->toBeTrue()->and($view->json('data.progress.next_lesson_id'))->toBe($l1->id);

    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $seq = 0; $clock = 1_000;
    watch($this, $sid, 0, 560, $seq, $clock);                       // 93%
    $this->getJson("/api/v1/learn/lessons/{$l2->id}")->assertOk();
    $this->getJson("/api/v1/learn/courses/{$course->id}/progress")->assertOk()->assertJsonPath('data.required_completed', 1)->assertJsonPath('data.percent', 50)->assertJsonPath('data.next_lesson_id', $l2->id);
});

it('honours a per-lesson completion threshold override and the course default', function () {
    [, $course, $l1] = videoCourse($this, ['video_completion_percent' => 50]);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    $seq = 0; $clock = 1_000;
    $r = watch($this, $sid, 0, 310, $seq, $clock);
    expect($r['completed'])->toBeTrue();   // 51.7% >= course rule of 50%
});

it('stops tracking and playback the moment enrolment is suspended', function () {
    [$student, $course, $l1] = videoCourse($this);
    Carbon::setTestNow(now());
    $sid = openSession($this, $l1);
    CourseEnrollment::where('user_id', $student->id)->update(['status' => 'suspended']);
    $this->postJson("/api/v1/learn/playback/{$sid}/events", ['events' => [['seq' => 1, 'type' => 'seek', 'position' => 1, 'client_ts_ms' => 1]]])->assertForbidden();
    $this->postJson("/api/v1/learn/lessons/{$l1->id}/playback")->assertForbidden();
});

it('never records staff previews as student progress', function () {
    $lect = $this->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $l = $course->lessons()->first();
    $l->forceFill(['type' => 'video', 'video_source' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ', 'duration_seconds' => 600])->save();
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $this->postJson("/api/v1/learn/lessons/{$l->id}/playback")->assertOk()->assertJsonPath('data.tracked', false)->assertJsonPath('data.session_id', null);
    expect(PlaybackSession::count())->toBe(0)->and(LessonProgress::count())->toBe(0);
});
