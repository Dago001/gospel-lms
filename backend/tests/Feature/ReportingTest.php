<?php

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LiveSession;
use App\Models\LiveSessionAttendance;
use App\Models\Payment;
use App\Services\ReportService;

function asRole($test, string $role, array $attrs = [])
{
    $u = $test->makeUser($role, $attrs);
    $test->actingAs($u)->withSession(['mfa_verified' => true]);

    return $u;
}

function enrolled(Course $c, $user, string $status = 'active', $at = null): CourseEnrollment
{
    return CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $user->id, 'status' => $status, 'source' => 'free', 'enrolled_at' => $at ?? now(), 'completed_at' => $status === 'completed' ? now() : null]);
}

it('reports enrolment, completion and pass-rate figures that match the underlying records', function () {
    $c = Course::factory()->published()->create(['title' => 'Hermeneutics']);
    $users = collect(range(1, 5))->map(fn () => $this->makeUser());
    foreach ($users->take(3) as $u) { enrolled($c, $u, 'active'); }
    foreach ($users->slice(3) as $u) { enrolled($c, $u, 'completed'); }
    $q = Assessment::factory()->create(['course_id' => $c->id, 'title' => 'Final']);
    foreach ([[true, 80], [true, 60], [false, 30], [false, 40]] as $i => [$passed, $pct]) {
        AssessmentAttempt::create(['assessment_id' => $q->id, 'assessment_version' => 1, 'user_id' => $users[$i]->id, 'attempt_no' => 1, 'status' => 'graded', 'started_at' => now(), 'question_set' => [], 'max_score' => 100,
            'total_score' => $pct, 'percent' => $pct, 'passed' => $passed, 'results_published_at' => now()]);
    }
    asRole($this, 'admin');
    $comp = $this->getJson('/api/v1/admin/reports/completion')->assertOk()->json('data.0');
    expect($comp['title'])->toBe('Hermeneutics')->and((int) $comp['enrolled'])->toBe(5)->and((int) $comp['completed'])->toBe(2)->and($comp['completion_rate'])->toEqual(40);
    $as = $this->getJson('/api/v1/admin/reports/assessments')->assertOk()->json('data.0');
    expect((int) $as['attempts'])->toBe(4)->and((int) $as['passed'])->toBe(2)->and($as['pass_rate'])->toEqual(50)->and($as['average_percent'])->toEqual(52.5);
    $en = $this->getJson('/api/v1/admin/reports/enrolments')->assertOk()->json('data');
    expect(collect($en['daily'])->sum('count'))->toBe(5)->and($en['by_course'][0]['count'])->toBe(5);
});

it('does not count unreleased results in pass rates', function () {
    $c = Course::factory()->published()->create();
    $q = Assessment::factory()->create(['course_id' => $c->id]);
    AssessmentAttempt::create(['assessment_id' => $q->id, 'assessment_version' => 1, 'user_id' => $this->makeUser()->id, 'attempt_no' => 1, 'status' => 'grading', 'started_at' => now(), 'question_set' => [], 'max_score' => 100]);
    asRole($this, 'admin');
    $row = $this->getJson('/api/v1/admin/reports/assessments')->json('data.0');
    expect((int) $row['attempts'])->toBe(0)->and($row['pass_rate'])->toBeNull();
    $this->getJson('/api/v1/admin/reports/grading-queue')->assertJsonPath('data.0.assessments_awaiting_marking', 1);
});

it('computes attendance rates for sessions that have actually been held', function () {
    $c = Course::factory()->published()->create();
    $a = $this->makeUser(); $b = $this->makeUser();
    enrolled($c, $a); enrolled($c, $b);
    $held = LiveSession::create(['course_id' => $c->id, 'title' => 'Held', 'starts_at' => now()->subDays(2), 'duration_minutes' => 60, 'provider' => 'manual', 'status' => 'ended']);
    LiveSession::create(['course_id' => $c->id, 'title' => 'Future', 'starts_at' => now()->addDays(2), 'duration_minutes' => 60, 'provider' => 'manual', 'status' => 'scheduled']);
    LiveSessionAttendance::create(['live_session_id' => $held->id, 'user_id' => $a->id, 'present' => true, 'minutes_attended' => 60, 'source' => 'manual']);
    asRole($this, 'admin');
    $rows = $this->getJson('/api/v1/admin/reports/attendance')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['attendance_rate'])->toEqual(50);
});

it('exports CSV with formula injection neutralised and records the export in the audit log', function () {
    Course::factory()->published()->create(['title' => '=HYPERLINK("http://evil","x")']);
    asRole($this, 'admin');
    $csv = $this->get('/api/v1/admin/reports/completion?format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain(",=HYPERLINK")->and($csv)->toContain('completion_rate');
    expect(App\Models\AuditLog::where('action', 'report.exported')->exists())->toBeTrue();
});

it('restricts reports by permission: lecturers, students and finance cannot read academic reports', function () {
    foreach (['student', 'lecturer', 'finance'] as $role) {
        asRole($this, $role);
        $this->getJson('/api/v1/admin/reports/completion')->assertForbidden();
        $this->getJson('/api/v1/admin/reports/enrolments?format=csv')->assertForbidden();
    }
});

it('builds the admin dashboard from real data and shows each role only the sections it may see', function () {
    $c = Course::factory()->published()->create();
    $s = $this->makeUser('student', ['last_login_at' => now()->subDays(2)]);
    $old = $this->makeUser('student', ['last_login_at' => now()->subDays(20)]);
    enrolled($c, $s); enrolled($c, $old, 'completed');
    Certificate::create(['number' => 'GLC-1', 'verification_code' => str_repeat('c', 32), 'user_id' => $s->id, 'course_id' => $c->id, 'holder_name' => 'x', 'course_title' => 'x', 'issued_at' => now()]);
    $order = App\Models\Order::create(['number' => 'GL-1', 'user_id' => $s->id, 'status' => 'paid', 'currency' => 'NGN', 'subtotal_minor' => 500000, 'discount_minor' => 0, 'total_minor' => 500000, 'paid_at' => now()]);
    Payment::create(['order_id' => $order->id, 'reference' => 'GLR1', 'amount_minor' => 500000, 'currency' => 'NGN', 'status' => 'success', 'paid_at' => now()]);

    asRole($this, 'admin');
    $d = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    expect($d['academic']['active_students']['last_7_days'])->toBe(1)->and($d['academic']['active_students']['last_30_days'])->toBe(2)->and($d['academic']['enrolments']['active'])->toBe(1)
        ->and($d['academic']['enrolments']['completed'])->toBe(1)->and($d['academic']['certificates']['active'])->toBe(1)->and($d['academic']['courses']['published'])->toBe(1);
    expect((int) $d['finance']['revenue_30_days'][0]['total_minor'])->toBe(500000);

    asRole($this, 'finance');
    $f = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
    expect($f)->toHaveKey('finance')->not->toHaveKey('academic');           // no academic data for finance
    asRole($this, 'lecturer');
    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
});

it('gives each student a dashboard of only their own progress, deadlines and sessions', function () {
    $c = Course::factory()->published()->withCurriculum(2)->create();
    $alice = $this->makeUser(); $bob = $this->makeUser();
    enrolled($c, $alice);
    App\Models\LessonCompletionRecord::create(['user_id' => $alice->id, 'lesson_id' => $c->lessons()->orderBy('position')->first()->id, 'course_id' => $c->id, 'method' => 'reading', 'completed_at' => now()]);
    App\Models\Assignment::factory()->create(['course_id' => $c->id, 'title' => 'Essay', 'due_at' => now()->addDays(3)]);
    LiveSession::create(['course_id' => $c->id, 'title' => 'Q&A', 'starts_at' => now()->addDays(2), 'duration_minutes' => 60, 'provider' => 'manual', 'status' => 'scheduled']);

    $d = $this->actingAs($alice)->getJson('/api/v1/student/dashboard')->assertOk()->json('data');
    expect($d['courses'])->toHaveCount(1)->and($d['courses'][0]['progress_percent'])->toEqual(50)->and($d['upcoming_deadlines'][0]['title'])->toBe('Essay')->and($d['upcoming_live_sessions'][0]['title'])->toBe('Q&A');
    $e = $this->actingAs($bob)->getJson('/api/v1/student/dashboard')->assertOk()->json('data');
    expect($e['courses'])->toBe([])->and($e['upcoming_deadlines'])->toBe([])->and($e['upcoming_live_sessions'])->toBe([]);
});

it('shows lecturers their own courses, students and marking queue only', function () {
    $lect = $this->makeUser('lecturer');
    $mine = Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Mine']);
    Course::factory()->published()->create(['title' => 'Not mine']);
    enrolled($mine, $this->makeUser());
    $a = App\Models\Assignment::factory()->create(['course_id' => $mine->id]);
    App\Models\AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $this->makeUser()->id, 'attempt' => 1, 'submitted_at' => now(), 'text_response' => 'x']);
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $d = $this->getJson('/api/v1/manage/dashboard')->assertOk()->json('data');
    expect($d['courses'])->toHaveCount(1)->and($d['courses'][0]['title'])->toBe('Mine')->and($d['courses'][0]['active_students'])->toBe(1)->and($d['courses'][0]['assignments_awaiting_grade'])->toBe(1)->and($d['totals']['awaiting_grading'])->toBe(1);
    $this->getJson("/api/v1/manage/courses/{$mine->id}/analytics")->assertOk();
    $other = Course::where('title', 'Not mine')->first();
    $this->getJson("/api/v1/manage/courses/{$other->id}/analytics")->assertForbidden();
    asRole($this, 'student');
    $this->getJson('/api/v1/manage/dashboard')->assertForbidden();
});
