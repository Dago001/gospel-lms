<?php

use App\Events\GradePublished;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LessonCompletionRecord;
use App\Models\LiveSession;
use App\Models\SubmissionGrade;
use App\Notifications\CertificateIssuedNotice;
use App\Notifications\CourseCompletedNotice;
use App\Services\CompletionEngine;
use Illuminate\Support\Facades\Notification;

/** Course: 1 required lesson, assignment (30%, pass 50), quiz (20%, pass 50), final exam (50%, pass 60, mandatory). Student enrolled. */
function gradedCourse($test, array $policy = []): array
{
    $lect = $test->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create(['completion_policy' => $policy ?: null]);
    $assignment = Assignment::factory()->create(['course_id' => $course->id, 'weight' => 30, 'pass_mark' => 50, 'max_score' => 100]);
    $quiz = Assessment::factory()->create(['course_id' => $course->id, 'kind' => 'quiz', 'weight' => 20, 'pass_mark_percent' => 50]);
    $final = Assessment::factory()->create(['course_id' => $course->id, 'kind' => 'exam', 'title' => 'Final Examination', 'weight' => 50, 'pass_mark_percent' => 60, 'is_final_exam' => true]);
    $student = $test->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    return [$lect, $student, $course, $assignment, $quiz, $final];
}

function doLessons($student, Course $course): void
{
    foreach ($course->lessons as $l) {
        LessonCompletionRecord::firstOrCreate(['user_id' => $student->id, 'lesson_id' => $l->id], ['course_id' => $course->id, 'method' => 'reading', 'completed_at' => now()]);
    }
}

function doAssignment($student, Assignment $a, float $score, bool $fire = true): void
{
    $s = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $student->id, 'attempt' => 1, 'submitted_at' => now(), 'status' => 'graded', 'text_response' => 'x']);
    SubmissionGrade::create(['submission_id' => $s->id, 'raw_score' => $score, 'final_score' => $score, 'status' => 'published', 'published_at' => now()]);
    $fire && event(new GradePublished($student->id, $a->course_id, 'assignment', $s->id));
}

function doAssessment($student, Assessment $a, float $percent, bool $fire = true): AssessmentAttempt
{
    $at = AssessmentAttempt::create(['assessment_id' => $a->id, 'assessment_version' => 1, 'user_id' => $student->id, 'attempt_no' => AssessmentAttempt::where(['assessment_id' => $a->id, 'user_id' => $student->id])->count() + 1,
        'status' => 'graded', 'started_at' => now(), 'submitted_at' => now(), 'question_set' => [], 'max_score' => 100, 'total_score' => $percent, 'percent' => $percent, 'passed' => $percent >= $a->pass_mark_percent,
        'graded_at' => now(), 'results_published_at' => now()]);
    $fire && event(new GradePublished($student->id, $a->course_id, 'assessment', $at->id));

    return $at;
}

function codes($check): array
{
    return array_column($check->reasons, 'code');
}

it('refuses completion and explains each missing requirement', function () {
    [, $student, $course] = gradedCourse($this);
    $check = app(CompletionEngine::class)->run($student, $course, 'test');
    expect($check->eligible)->toBeFalse()->and(codes($check))->toContain('lessons_incomplete', 'assignment_not_submitted', 'assessment_not_taken', 'final_exam_not_taken');
    expect(Certificate::count())->toBe(0)->and(CourseEnrollment::first()->status)->toBe('active');
    $this->actingAs($student)->getJson("/api/v1/learn/courses/{$course->id}/completion")->assertOk()->assertJsonPath('data.eligible', false)
        ->assertJsonFragment(['code' => 'final_exam_not_taken']);
});

it('denies a certificate to a student who fails the final exam even with an excellent average elsewhere', function () {
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this);
    doLessons($student, $course);
    doAssignment($student, $assignment, 100, false);
    doAssessment($student, $quiz, 100, false);
    doAssessment($student, $final, 55);                              // below the 60% pass mark; weighted average is still 77.5%
    $check = app(CompletionEngine::class)->run($student, $course, "test");
    expect($check->grade_percent)->toEqual(77.5)->and($check->eligible)->toBeFalse()->and(codes($check))->toBe(['final_exam_failed']);
    expect(Certificate::count())->toBe(0)->and(CourseEnrollment::first()->status)->toBe('active');
});

it('completes the course and issues exactly one certificate once every component passes', function () {
    Notification::fake();
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this);
    doLessons($student, $course);
    doAssignment($student, $assignment, 80);
    doAssessment($student, $quiz, 70);
    doAssessment($student, $final, 75);                              // each event re-runs the engine; the last one completes
    $c = Certificate::firstOrFail();
    expect(Certificate::count())->toBe(1)->and($c->status)->toBe('active')->and($c->holder_name)->toBe($student->name)->and($c->course_title)->toBe($course->title)
        ->and($c->number)->toStartWith('GLC-')->and($c->verification_code)->toHaveLength(32);
    expect(CourseEnrollment::first())->status->toBe('completed')->completed_at->not->toBeNull();
    expect(app(CompletionEngine::class)->run($student, $course, 'again')->grade_percent)->toEqual(75.5);   // .3*80 + .2*70 + .5*75
    Notification::assertSentToTimes($student, CourseCompletedNotice::class, 1);
    Notification::assertSentToTimes($student, CertificateIssuedNotice::class, 1);
});

it('never creates a duplicate active certificate however often completion is requested', function () {
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this);
    doLessons($student, $course);
    doAssignment($student, $assignment, 90, false);
    doAssessment($student, $quiz, 90, false);
    doAssessment($student, $final, 90, false);
    $engine = app(CompletionEngine::class);
    foreach (range(1, 6) as $_) {
        $engine->run($student, $course, 'repeat');
        $this->actingAs($student)->getJson("/api/v1/learn/courses/{$course->id}/completion")->assertOk();
    }
    expect(Certificate::count())->toBe(1);
    expect(fn () => Certificate::create(['number' => 'X1', 'verification_code' => str_repeat('a', 32), 'user_id' => $student->id, 'course_id' => $course->id, 'status' => 'active', 'holder_name' => 'x', 'course_title' => 'x', 'issued_at' => now()]))
        ->toThrow(Illuminate\Database\QueryException::class);   // the database itself forbids a second active certificate
});

it('applies the weighted pass mark and withholds completion below it', function () {
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this, ['course_pass_percent' => 70]);
    doLessons($student, $course);
    doAssignment($student, $assignment, 52, false);          // each component passes alone...
    doAssessment($student, $quiz, 55, false);
    doAssessment($student, $final, 62, false);                // ...but 0.3*52 + 0.2*55 + 0.5*62 = 57.6 < 70
    $check = app(CompletionEngine::class)->run($student, $course, 'test');
    expect($check->eligible)->toBeFalse()->and(codes($check))->toBe(['course_grade_below_pass'])->and($check->grade_percent)->toEqual(57.6);
});

it('flags weights that do not add up to 100', function () {
    [$lect, , $course, $assignment] = gradedCourse($this);
    expect(app(CompletionEngine::class)->weightProblems($course))->toBe([]);
    $assignment->update(['weight' => 45]);
    expect(app(CompletionEngine::class)->weightProblems($course)[0])->toContain('115');
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->getJson("/api/v1/manage/courses/{$course->id}/completion-policy")->assertOk()->assertJsonPath('data.problems.0', fn ($m) => str_contains($m, '115'));
});

it('lets a passed supplementary assessment stand in for a failed original', function () {
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this);
    $sup = Assessment::factory()->create(['course_id' => $course->id, 'kind' => 'supplementary', 'parent_assessment_id' => $final->id, 'pass_mark_percent' => 60, 'weight' => 0]);
    doLessons($student, $course);
    doAssignment($student, $assignment, 80, false);
    doAssessment($student, $quiz, 70, false);
    doAssessment($student, $final, 40, false);
    expect(app(CompletionEngine::class)->run($student, $course, 't')->eligible)->toBeFalse();
    doAssessment($student, $sup, 68);
    expect(Certificate::count())->toBe(1);
});

it('uses the best attempt by default and the latest when the policy says so', function () {
    foreach (['best' => true, 'latest' => false] as $mode => $expected) {
        [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this, ['attempt_selection' => $mode]);
        doLessons($student, $course);
        doAssignment($student, $assignment, 80, false);
        doAssessment($student, $quiz, 70, false);
        doAssessment($student, $final, 90, false);
        doAssessment($student, $final, 30, false);          // a later, worse attempt
        expect(app(CompletionEngine::class)->run($student, $course, 't')->eligible)->toBe($expected);
    }
});

it('waits for pending marking rather than guessing', function () {
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this);
    doLessons($student, $course);
    $s = AssignmentSubmission::create(['assignment_id' => $assignment->id, 'user_id' => $student->id, 'attempt' => 1, 'submitted_at' => now(), 'text_response' => 'x']);
    SubmissionGrade::create(['submission_id' => $s->id, 'raw_score' => 90, 'final_score' => 90, 'status' => 'draft']);      // a draft grade must not count
    $pending = doAssessment($student, $quiz, 90, false);
    $pending->update(['results_published_at' => null, 'status' => 'grading']);                                              // unreleased result must not count
    $check = app(CompletionEngine::class)->run($student, $course, 't');
    expect(codes($check))->toContain('assignment_awaiting_grade', 'assessment_awaiting_results');
});

it('requires attendance and lecturer approval when the course demands them', function () {
    [$lect, $student, $course, $assignment, $quiz, $final] = gradedCourse($this, ['min_attendance_percent' => 75, 'lecturer_approval_required' => true]);
    doLessons($student, $course);
    doAssignment($student, $assignment, 80, false);
    doAssessment($student, $quiz, 70, false);
    doAssessment($student, $final, 75, false);
    $s1 = LiveSession::create(['course_id' => $course->id, 'title' => 'W1', 'starts_at' => now()->subDays(3), 'duration_minutes' => 60, 'provider' => 'manual', 'status' => 'ended']);
    $s2 = LiveSession::create(['course_id' => $course->id, 'title' => 'W2', 'starts_at' => now()->subDays(2), 'duration_minutes' => 60, 'provider' => 'manual', 'status' => 'ended']);
    $engine = app(CompletionEngine::class);
    expect(codes($engine->run($student, $course, 't')))->toBe(['attendance_too_low']);              // 0 of 2
    App\Models\LiveSessionAttendance::create(['live_session_id' => $s1->id, 'user_id' => $student->id, 'minutes_attended' => 55, 'present' => true, 'source' => 'manual']);
    expect(codes($engine->run($student, $course, 't')))->toBe(['attendance_too_low']);              // 50% < 75%
    App\Models\LiveSessionAttendance::create(['live_session_id' => $s2->id, 'user_id' => $student->id, 'minutes_attended' => 60, 'present' => true, 'source' => 'manual']);
    expect(codes($engine->run($student, $course, 't')))->toBe(['awaiting_lecturer_approval']);

    $this->actingAs($this->makeUser('lecturer'))->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/courses/{$course->id}/students/{$student->id}/approve-completion")->assertForbidden();
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/courses/{$course->id}/students/{$student->id}/approve-completion")->assertOk()->assertJsonPath('data.eligible', true);
    expect(Certificate::count())->toBe(1);
});

it('keeps an issued certificate but records a regression when a grade is later amended below the bar', function () {
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($this);
    doLessons($student, $course);
    doAssignment($student, $assignment, 80);
    doAssessment($student, $quiz, 70);
    $at = doAssessment($student, $final, 75);
    expect(Certificate::count())->toBe(1);
    $at->update(['passed' => false, 'percent' => 40]);
    event(new GradePublished($student->id, $course->id, 'assessment', $at->id));
    expect(CourseCompletionCheckEligible($student, $course))->toBeFalse()->and(Certificate::where('status', 'active')->count())->toBe(1);   // never silently revoked
    expect(AuditLog::where('action', 'completion.regressed')->exists())->toBeTrue();
});

function CourseCompletionCheckEligible($student, $course): bool
{
    return App\Models\CourseCompletionCheck::where(['user_id' => $student->id, 'course_id' => $course->id])->firstOrFail()->eligible;
}

it('re-evaluates automatically when a lecturer publishes the final grade through the real grading endpoint', function () {
    [$lect, $student, $course, $assignment, $quiz, $final] = gradedCourse($this);
    $assignment->update(['weight' => 100]);                       // single weighted item keeps this scenario focused
    $quiz->update(['is_required' => false, 'weight' => 0]);
    $final->update(['is_required' => false, 'is_final_exam' => false, 'weight' => 0]);
    doLessons($student, $course);
    $sub = AssignmentSubmission::create(['assignment_id' => $assignment->id, 'user_id' => $student->id, 'attempt' => 1, 'submitted_at' => now(), 'text_response' => 'x']);
    expect(Certificate::count())->toBe(0);
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 88, 'publish' => true])->assertOk();
    expect(Certificate::count())->toBe(1);
});

// ---------------------------------------------------------------- certificates
function issuedCertificate($test): array
{
    [, $student, $course, $assignment, $quiz, $final] = gradedCourse($test);
    doLessons($student, $course);
    doAssignment($student, $assignment, 80, false);
    doAssessment($student, $quiz, 70, false);
    doAssessment($student, $final, 75, false);
    app(CompletionEngine::class)->run($student, $course, 't');

    return [$student, $course, Certificate::firstOrFail()];
}

it('generates a PDF certificate for its owner and refuses everyone else', function () {
    [$student, , $cert] = issuedCertificate($this);
    $res = $this->actingAs($student)->get("/api/v1/learn/certificates/{$cert->id}/pdf")->assertOk();
    expect(substr($res->getContent(), 0, 4))->toBe('%PDF')->and(strlen($res->getContent()))->toBeGreaterThan(5000);
    $this->actingAs($this->makeUser())->get("/api/v1/learn/certificates/{$cert->id}/pdf", ['Accept' => 'application/json'])->assertNotFound();
    $this->getJson('/api/v1/learn/certificates')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($student)->getJson('/api/v1/learn/certificates')->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $cert->number);
});

it('verifies a certificate publicly with minimal disclosure, and logs the check', function () {
    [$student, , $cert] = issuedCertificate($this);
    $res = $this->getJson("/api/v1/public/certificates/verify/{$cert->verification_code}")->assertOk()->assertJsonPath('data.status', 'valid')
        ->assertJsonPath('data.holder_name', $student->name)->assertJsonPath('data.certificate_number', $cert->number);
    $body = json_encode($res->json());
    expect($body)->not->toContain($student->email)->not->toContain($student->id)->not->toContain('grade')->not->toContain('percent');
    expect(DB::table('certificate_verification_logs')->where(['certificate_id' => $cert->id, 'result' => 'valid'])->count())->toBe(1);
    expect(strtoupper($cert->verification_code))->not->toBe($cert->verification_code);
    $this->getJson('/api/v1/public/certificates/verify/'.strtoupper($cert->verification_code))->assertOk();   // codes are case-insensitive for people retyping them
});

it('returns the same not-found answer for unknown, malformed and guessed codes', function () {
    $this->getJson('/api/v1/public/certificates/verify/'.str_repeat('a', 32))->assertNotFound()->assertJsonPath('data.status', 'not_found');
    $this->getJson('/api/v1/public/certificates/verify/short')->assertNotFound();
    $this->getJson('/api/v1/public/certificates/verify/'.urlencode("' OR 1=1 --"))->assertNotFound();
    expect(DB::table('certificate_verification_logs')->where('result', 'not_found')->count())->toBe(3);
});

it('rate limits certificate verification to slow down code guessing', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/api/v1/public/certificates/verify/'.str_repeat('b', 32))->assertNotFound();
    }
    $this->getJson('/api/v1/public/certificates/verify/'.str_repeat('b', 32))->assertStatus(429);
});

it('lets only administrators revoke, requires a reason, and shows revoked status publicly without the reason', function () {
    [$student, , $cert] = issuedCertificate($this);
    foreach (['student', 'lecturer', 'finance'] as $role) {
        $this->actingAs($this->makeUser($role))->withSession(['mfa_verified' => true])->postJson("/api/v1/admin/certificates/{$cert->id}/revoke", ['reason' => 'Because I want to'])->assertForbidden();
    }
    $admin = $this->makeUser('admin');
    $this->actingAs($admin)->withSession(['mfa_verified' => true]);
    $this->postJson("/api/v1/admin/certificates/{$cert->id}/revoke", ['reason' => 'short'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/certificates/{$cert->id}/revoke", ['reason' => 'Plagiarism found in final project'])->assertOk()->assertJsonPath('data.status', 'revoked');
    $this->postJson("/api/v1/admin/certificates/{$cert->id}/revoke", ['reason' => 'Plagiarism found in final project'])->assertStatus(409);

    $pub = $this->getJson("/api/v1/public/certificates/verify/{$cert->verification_code}")->assertOk()->assertJsonPath('data.status', 'revoked');
    expect(json_encode($pub->json()))->not->toContain('Plagiarism');
    $this->actingAs($student)->get("/api/v1/learn/certificates/{$cert->id}/pdf", ['Accept' => 'application/json'])->assertStatus(410);
    $log = AuditLog::where('action', 'certificate.revoked')->firstOrFail();
    expect($log->reason)->toBe('Plagiarism found in final project')->and($log->actor_id)->toBe($admin->id)->and($log->old_values['status'])->toBe('active');
    expect(fn () => App\Models\CertificateRevocation::query()->delete())->toThrow(Illuminate\Database\QueryException::class);   // history is immutable
});

it('does not silently reissue after a revocation, but an administrator can reissue with a reason', function () {
    [$student, $course, $cert] = issuedCertificate($this);
    $admin = $this->makeUser('admin');
    $this->actingAs($admin)->withSession(['mfa_verified' => true]);
    $this->postJson("/api/v1/admin/certificates/{$cert->id}/revoke", ['reason' => 'Issued in error pending review'])->assertOk();
    app(CompletionEngine::class)->run($student, $course, 'again');
    expect(Certificate::where('status', 'active')->count())->toBe(0);             // the engine respects block_reissue

    $this->postJson("/api/v1/admin/certificates/{$cert->id}/reissue", ['reason' => 'Review cleared the student'])->assertCreated();
    $new = Certificate::where('status', 'active')->firstOrFail();
    expect($new->id)->not->toBe($cert->id)->and($new->verification_code)->not->toBe($cert->verification_code)->and(Certificate::count())->toBe(2);
    $this->getJson("/api/v1/public/certificates/verify/{$cert->verification_code}")->assertJsonPath('data.status', 'revoked');   // the old one stays revoked
    $this->getJson("/api/v1/public/certificates/verify/{$new->verification_code}")->assertJsonPath('data.status', 'valid');
});

it('offers no way for a client to request a certificate directly', function () {
    [$student, $course] = issuedCertificate($this);
    $other = $this->makeUser();
    foreach (['/api/v1/learn/certificates', '/api/v1/student/certificates', '/api/v1/admin/certificates'] as $url) {
        $status = $this->actingAs($other)->postJson($url, ['course_id' => $course->id, 'user_id' => $other->id])->status();
        expect($status)->toBeIn([403, 404, 405]);     // no route accepts a create request
    }
    expect(Certificate::where('user_id', $other->id)->count())->toBe(0);
});

it('keeps students and unrelated lecturers away from completion data', function () {
    [$lect, $student, $course] = gradedCourse($this);
    $other = $this->makeUser();
    $this->actingAs($other)->getJson("/api/v1/learn/courses/{$course->id}/completion")->assertForbidden();
    $this->actingAs($this->makeUser('lecturer'))->withSession(['mfa_verified' => true]);
    $this->getJson("/api/v1/manage/courses/{$course->id}/completion-report")->assertForbidden();
    $this->getJson("/api/v1/manage/courses/{$course->id}/students/{$student->id}/completion")->assertForbidden();
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->getJson("/api/v1/manage/courses/{$course->id}/completion-report")->assertOk()->assertJsonCount(1, 'data');
});
