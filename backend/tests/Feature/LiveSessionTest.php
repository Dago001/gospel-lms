<?php

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LiveSession;
use App\Models\LiveSessionAttendance;
use App\Models\LiveSessionRecording;
use App\Notifications\DeadlineReminder;
use App\Notifications\LiveClassReminder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['services.zoom' => ['account_id' => 'acc', 'client_id' => 'cid', 'client_secret' => 'sec', 'webhook_secret_token' => 'zoom_whsec', 'host_user' => 'me',
        'base_url' => 'https://api.zoom.us/v2', 'oauth_url' => 'https://zoom.us/oauth/token']]);
    Cache::forget('zoom.s2s.token');
});

function fakeZoom(array $extra = []): void
{
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake($extra + [
        'zoom.us/oauth/token' => Http::response(['access_token' => 'zt', 'expires_in' => 3600]),
        'api.zoom.us/v2/users/me/meetings' => fn ($r) => Http::response(['id' => 987654321, 'join_url' => 'https://zoom.us/j/987654321?pwd=shared', 'start_url' => 'https://zoom.us/s/987654321?zak=HOSTSECRET'], 201),
        'api.zoom.us/v2/meetings/*/registrants' => fn ($r) => Http::response(['registrant_id' => 'reg_'.substr(md5($r['email']), 0, 6), 'join_url' => 'https://zoom.us/w/987654321?tk='.substr(md5($r['email']), 0, 10)], 201),
    ]);
}

function liveCourse($test): array
{
    $lect = $test->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $student = $test->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    return [$lect, $student, $course];
}

function zoomSession(Course $course, array $o = []): LiveSession
{
    return LiveSession::create($o + ['course_id' => $course->id, 'title' => 'Hermeneutics Q&A', 'starts_at' => now()->addMinutes(5), 'duration_minutes' => 60, 'provider' => 'zoom', 'provider_meeting_id' => '987654321',
        'join_url' => 'https://zoom.us/j/987654321?pwd=shared', 'host_url' => 'https://zoom.us/s/987654321?zak=HOSTSECRET', 'status' => 'scheduled']);
}

function zoomHook($test, array $payload, ?string $secret = 'zoom_whsec', ?int $ts = null)
{
    $raw = json_encode($payload);
    $ts ??= time();
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ZM_REQUEST_TIMESTAMP' => (string) $ts, 'HTTP_X_ZM_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:$ts:$raw", $secret)];

    return $test->call('POST', '/api/v1/webhooks/zoom', [], [], [], $headers, $raw);
}

it('creates a Zoom meeting server-side with safe settings and never shows the host link to learners', function () {
    fakeZoom();
    [$lect, $student, $course] = liveCourse($this);
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $start = now()->addDays(2)->startOfHour();
    $res = $this->postJson("/api/v1/manage/courses/{$course->id}/live-sessions", ['title' => 'Romans Q&A', 'starts_at' => $start->toIso8601String(), 'duration_minutes' => 90, 'provider' => 'zoom'])->assertCreated();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/users/me/meetings') && $r['duration'] === 90 && $r['start_time'] === $start->utc()->format('Y-m-d\TH:i:s\Z')
        && $r['settings']['waiting_room'] === true && $r['settings']['registration_type'] === 1 && $r['settings']['join_before_host'] === false);
    expect(json_encode($res->json()))->not->toContain('HOSTSECRET')->not->toContain('zoom.us/j/');
    $this->actingAs($student)->getJson("/api/v1/learn/courses/{$course->id}/live-sessions")->assertOk()->assertJsonCount(1, 'data');
    expect(json_encode($this->getJson("/api/v1/learn/courses/{$course->id}/live-sessions")->json()))->not->toContain('zoom.us');
});

it('validates scheduling input and keeps other lecturers, students and finance out', function () {
    fakeZoom();
    [$lect, , $course] = liveCourse($this);
    $url = "/api/v1/manage/courses/{$course->id}/live-sessions";
    $ok = ['title' => 'x', 'duration_minutes' => 30, 'provider' => 'zoom', 'starts_at' => now()->addDay()->toIso8601String()];
    foreach (['student', 'finance', 'lecturer'] as $role) {
        $this->actingAs($this->makeUser($role))->withSession(['mfa_verified' => true])->postJson($url, $ok)->assertForbidden();
    }
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $this->postJson($url, [...$ok, 'starts_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
    $this->postJson($url, [...$ok, 'duration_minutes' => 9999])->assertUnprocessable();
    $this->postJson($url, [...$ok, 'provider' => 'manual'])->assertUnprocessable();                                   // manual needs a link
    $this->postJson($url, [...$ok, 'provider' => 'manual', 'join_url' => 'http://insecure.example/meet'])->assertUnprocessable();   // https only
    $this->postJson($url, [...$ok, 'provider' => 'manual', 'join_url' => 'https://meet.example/room'])->assertCreated();
});

it('fails cleanly, creating nothing, when Zoom is unavailable', function () {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['zoom.us/oauth/token' => Http::response(['access_token' => 'zt']), 'api.zoom.us/*' => Http::response(['message' => 'down'], 503)]);
    [$lect, , $course] = liveCourse($this);
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/courses/{$course->id}/live-sessions", ['title' => 'x', 'duration_minutes' => 30, 'provider' => 'zoom', 'starts_at' => now()->addDay()->toIso8601String()])
        ->assertStatus(503)->assertJsonPath('code', 'zoom_unavailable');
    expect(LiveSession::count())->toBe(0);
});

it('gives each enrolled learner their own join link, only near the start, and never to outsiders', function () {
    fakeZoom();
    [, $alice, $course] = liveCourse($this);
    $bob = $this->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $bob->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $s = zoomSession($course);

    $a = $this->actingAs($alice)->getJson("/api/v1/learn/live-sessions/{$s->id}/join")->assertOk()->json('data.join_url');
    $b = $this->actingAs($bob)->getJson("/api/v1/learn/live-sessions/{$s->id}/join")->assertOk()->json('data.join_url');
    expect($a)->not->toBe($b)->and($a)->not->toContain('pwd=shared');
    $this->actingAs($alice)->getJson("/api/v1/learn/live-sessions/{$s->id}/join")->assertOk();
    Http::assertSentCount(1 + 2);                                                       // token once + one registrant per learner; repeat joins reuse the stored link
    $this->actingAs($this->makeUser())->getJson("/api/v1/learn/live-sessions/{$s->id}/join")->assertForbidden();

    $later = zoomSession($course, ['provider_meeting_id' => '111', 'starts_at' => now()->addHours(3)]);
    $this->actingAs($alice)->getJson("/api/v1/learn/live-sessions/{$later->id}/join")->assertStatus(409)->assertJsonPath('code', 'not_joinable');
    $s->update(['status' => 'cancelled']);
    $this->getJson("/api/v1/learn/live-sessions/{$s->id}/join")->assertStatus(409);
});

it('answers Zoom\'s endpoint validation and rejects unsigned or stale webhooks', function () {
    $r = zoomHook($this, ['event' => 'endpoint.url_validation', 'payload' => ['plainToken' => 'abc123']], 'x')->assertOk();
    expect($r->json('encryptedToken'))->toBe(hash_hmac('sha256', 'abc123', 'zoom_whsec'))->and($r->json('plainToken'))->toBe('abc123');
    zoomHook($this, ['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '987654321']]], 'WRONG')->assertUnauthorized();
    zoomHook($this, ['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '987654321']]], 'zoom_whsec', time() - 3600)->assertUnauthorized();
    $this->postJson('/api/v1/webhooks/zoom', ['event' => 'meeting.ended'])->assertUnauthorized();
});

it('records attendance from Zoom\'s report when a meeting ends: sums rejoins, ignores guests, applies the 75% rule', function () {
    [, $alice, $course] = liveCourse($this);
    $bob = $this->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $bob->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $s = zoomSession($course, ['starts_at' => now()->subHours(2)]);
    fakeZoom(['api.zoom.us/v2/report/meetings/987654321/participants*' => Http::response(['participants' => [
        ['user_email' => strtoupper($alice->email), 'duration' => 30 * 60], ['user_email' => $alice->email, 'duration' => 20 * 60],     // two joins: 50 min >= 45 needed
        ['user_email' => $bob->email, 'duration' => 20 * 60],                                                                          // 20 min: absent
        ['user_email' => 'stranger@example.com', 'duration' => 3600],                                                                  // not a learner
        ['user_email' => '', 'duration' => 3600],
    ]])]);
    zoomHook($this, ['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '987654321']]])->assertOk();
    expect($s->fresh()->status)->toBe('ended')->and($s->fresh()->attendance_synced_at)->not->toBeNull();
    $a = LiveSessionAttendance::where(['live_session_id' => $s->id, 'user_id' => $alice->id])->firstOrFail();
    $b = LiveSessionAttendance::where(['live_session_id' => $s->id, 'user_id' => $bob->id])->firstOrFail();
    expect($a->minutes_attended)->toBe(50)->and($a->present)->toBeTrue()->and($b->minutes_attended)->toBe(20)->and($b->present)->toBeFalse()->and(LiveSessionAttendance::count())->toBe(2);
    zoomHook($this, ['event' => 'meeting.ended', 'payload' => ['object' => ['id' => '987654321']]])->assertOk();    // duplicate delivery
    expect($a->fresh()->minutes_attended)->toBe(50)->and(LiveSessionAttendance::count())->toBe(2);
});

it('lets a lecturer override attendance with a reason, and the override survives later Zoom syncs', function () {
    [$lect, $alice, $course] = liveCourse($this);
    $s = zoomSession($course, ['starts_at' => now()->subHours(2), 'status' => 'ended']);
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $this->putJson("/api/v1/manage/live-sessions/{$s->id}/attendance/{$alice->id}", ['present' => true])->assertUnprocessable();       // reason required
    $this->putJson("/api/v1/manage/live-sessions/{$s->id}/attendance/{$alice->id}", ['present' => true, 'note' => 'Joined on phone with a different email'])->assertOk()->assertJsonPath('data.source', 'manual');
    fakeZoom(['api.zoom.us/v2/report/meetings/987654321/participants*' => Http::response(['participants' => [['user_email' => $alice->email, 'duration' => 60]]])]);
    app(App\Services\Live\LiveSessionService::class)->syncAttendance($s);
    expect(LiveSessionAttendance::first())->present->toBeTrue()->source->toBe('manual');
    expect(App\Models\AuditLog::where('action', 'attendance.set')->count())->toBe(1);
    $stranger = $this->makeUser();
    $this->putJson("/api/v1/manage/live-sessions/{$s->id}/attendance/{$stranger->id}", ['present' => true, 'note' => 'not enrolled'])->assertUnprocessable()->assertJsonPath('code', 'not_enrolled');
});

it('holds recordings back until the lecturer releases them, then shows them only to enrolled learners', function () {
    [$lect, $alice, $course] = liveCourse($this);
    $s = zoomSession($course, ['status' => 'ended']);
    $payload = ['event' => 'recording.completed', 'payload' => ['object' => ['id' => '987654321', 'recording_files' => [
        ['id' => 'rec1', 'file_type' => 'MP4', 'status' => 'completed', 'play_url' => 'https://zoom.us/rec/play/abc', 'recording_start' => '2026-10-20T10:00:00Z', 'recording_end' => '2026-10-20T11:00:00Z'],
        ['id' => 'aud1', 'file_type' => 'M4A', 'status' => 'completed', 'play_url' => 'https://zoom.us/rec/play/audio'],
    ]]]];
    zoomHook($this, $payload)->assertOk();
    zoomHook($this, $payload)->assertOk();                                          // duplicate
    expect(LiveSessionRecording::count())->toBe(1)->and(LiveSessionRecording::first()->published)->toBeFalse()->and(LiveSessionRecording::first()->duration_seconds)->toBe(3600);

    $this->actingAs($alice)->getJson("/api/v1/learn/live-sessions/{$s->id}/recordings")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $rec = LiveSessionRecording::first();
    expect(json_encode($this->getJson("/api/v1/manage/live-sessions/{$s->id}/recordings")->json()))->not->toContain('zoom.us');
    $this->putJson("/api/v1/manage/recordings/{$rec->id}", ['published' => true])->assertOk();
    $this->actingAs($alice)->getJson("/api/v1/learn/live-sessions/{$s->id}/recordings")->assertJsonPath('data.0.play_url', 'https://zoom.us/rec/play/abc');
    $this->actingAs($this->makeUser())->getJson("/api/v1/learn/live-sessions/{$s->id}/recordings")->assertForbidden();
});

it('sends each live-class and deadline reminder exactly once, only to the right learners', function () {
    Notification::fake();
    [, $alice, $course] = liveCourse($this);
    $bob = $this->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $bob->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $outsider = $this->makeUser();
    zoomSession($course, ['starts_at' => now()->addMinutes(60)]);
    $a = Assignment::factory()->create(['course_id' => $course->id, 'due_at' => now()->addHours(24)]);
    App\Models\AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $alice->id, 'attempt' => 1, 'submitted_at' => now(), 'text_response' => 'done']);

    $this->artisan('reminders:send')->assertSuccessful();
    $this->artisan('reminders:send')->assertSuccessful();                          // second run must not duplicate
    Notification::assertSentToTimes($alice, LiveClassReminder::class, 1);
    Notification::assertSentToTimes($bob, LiveClassReminder::class, 1);
    Notification::assertNotSentTo($outsider, LiveClassReminder::class);
    Notification::assertNotSentTo($alice, DeadlineReminder::class);                // already submitted
    Notification::assertSentToTimes($bob, DeadlineReminder::class, 1);
});
