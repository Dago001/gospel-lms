<?php

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\GradeChangeLog;
use App\Models\GradingRubric;
use App\Models\SubmissionGrade;
use App\Notifications\GradePublishedNotice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('private'));
afterEach(fn () => Carbon::setTestNow());

/** [lecturer, student, course, assignment] with the student enrolled. */
function assignmentSetup($test, array $over = []): array
{
    $lect = $test->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $a = Assignment::factory()->create(['course_id' => $course->id] + $over);
    $student = $test->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    return [$lect, $student, $course, $a];
}

function asLecturer($test, $u)
{
    $test->actingAs($u)->withSession(['mfa_verified' => true]);
}

function pdf(string $name = 'essay.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

it('accepts an on-time submission with a valid file and stores it privately with a checksum', function () {
    [, $student, , $a] = assignmentSetup($this);
    $res = $this->actingAs($student)->post("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'My essay', 'files' => [pdf()]], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.attempt', 1);
    $file = App\Models\SubmissionFile::firstOrFail();
    Storage::disk('private')->assertExists($file->path);
    expect($file->path)->toStartWith("submissions/{$a->id}/{$student->id}/")->and($file->sha256)->toHaveLength(64);
    $this->getJson("/api/v1/learn/assignments/{$a->id}")->assertJsonCount(1, 'data.my_submissions');
});

it('rejects empty, wrongly typed, oversized, spoofed and over-numerous files', function () {
    [, $student, , $a] = assignmentSetup($this, ['max_file_mb' => 1, 'max_files' => 2]);
    $this->actingAs($student);
    $post = fn (array $d) => $this->post("/api/v1/learn/assignments/{$a->id}/submissions", $d, ['Accept' => 'application/json']);
    $post([])->assertUnprocessable()->assertJsonPath('code', 'empty_submission');
    $post(['files' => [UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')]])->assertUnprocessable()->assertJsonPath('code', 'file_type_not_allowed');
    $post(['files' => [UploadedFile::fake()->createWithContent('fake.pdf', '<?php system($_GET[1]);')]])->assertUnprocessable()->assertJsonPath('code', 'file_content_mismatch');
    $post(['files' => [UploadedFile::fake()->create('big.pdf', 2048)->mimeType('application/pdf')]])->assertUnprocessable();
    $post(['files' => [pdf('a.pdf'), pdf('b.pdf'), pdf('c.pdf')]])->assertUnprocessable()->assertJsonPath('code', 'too_many_files');
    expect(AssignmentSubmission::count())->toBe(0);
});

it('enforces deadlines: reject after due date, or accept as late under a penalty policy until the cutoff', function () {
    [, $student, , $a] = assignmentSetup($this, ['due_at' => now()->addDay()]);
    $this->actingAs($student);
    Carbon::setTestNow(now()->addDays(2));
    $this->postJson("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'late'])->assertUnprocessable()->assertJsonPath('code', 'deadline_passed');

    $a->update(['late_policy' => 'penalty', 'late_penalty_percent' => 10, 'late_cutoff_at' => now()->addDay()]);
    $this->postJson("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'late'])->assertCreated()->assertJsonPath('data.is_late', true);
    expect(AssignmentSubmission::first()->late_minutes)->toBeGreaterThan(1400);

    [, $s2, , $b] = assignmentSetup($this, ['due_at' => now()->subDays(3), 'late_policy' => 'penalty', 'late_penalty_percent' => 10, 'late_cutoff_at' => now()->subDay()]);
    $this->actingAs($s2)->postJson("/api/v1/learn/assignments/{$b->id}/submissions", ['text_response' => 'too late'])->assertUnprocessable()->assertJsonPath('code', 'deadline_passed');
});

it('blocks unenrolled students, unpublished assignments and not-yet-open assignments', function () {
    [, , , $a] = assignmentSetup($this);
    $this->actingAs($this->makeUser())->postJson("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'x'])->assertForbidden();
    [, $s, , $draft] = assignmentSetup($this, ['status' => 'draft']);
    $this->actingAs($s)->postJson("/api/v1/learn/assignments/{$draft->id}/submissions", ['text_response' => 'x'])->assertNotFound();
    [, $s2, , $future] = assignmentSetup($this, ['opens_at' => now()->addDay(), 'due_at' => now()->addDays(3)]);
    $this->actingAs($s2)->postJson("/api/v1/learn/assignments/{$future->id}/submissions", ['text_response' => 'x'])->assertStatus(409)->assertJsonPath('code', 'not_open');
});

it('limits attempts and resubmission, and allows another attempt only after return for revision', function () {
    [$lect, $student, , $a] = assignmentSetup($this);
    $this->actingAs($student)->postJson("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'one'])->assertCreated();
    $this->postJson("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'two'])->assertStatus(409)->assertJsonPath('code', 'resubmission_not_allowed');

    $a->update(['max_attempts' => 2]);
    $sub = AssignmentSubmission::first();
    asLecturer($this, $lect);
    $this->postJson("/api/v1/manage/submissions/{$sub->id}/return")->assertOk();
    $this->actingAs($student)->postJson("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'two'])->assertCreated()->assertJsonPath('data.attempt', 2);
    $a->update(['allow_resubmission' => true]);
    $this->postJson("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'three'])->assertStatus(409)->assertJsonPath('code', 'attempt_limit_reached');
    expect(AssignmentSubmission::count())->toBe(2);
});

it('lets a lecturer grade, validates the range, and hides drafts from the student until published', function () {
    Notification::fake();
    [$lect, $student, , $a] = assignmentSetup($this);
    $sub = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $student->id, 'attempt' => 1, 'text_response' => 'x', 'submitted_at' => now()]);
    asLecturer($this, $lect);
    foreach ([-1, 100.01, 'abc', null] as $bad) {
        $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => $bad])->assertUnprocessable();
    }
    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 72.5, 'feedback' => '<p>Good <script>x</script></p>'])->assertOk()->assertJsonPath('data.status', 'draft');
    $this->actingAs($student)->getJson("/api/v1/learn/assignments/{$a->id}")->assertJsonPath('data.my_submissions.0.grade', null);
    Notification::assertNothingSent();

    asLecturer($this, $lect);
    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 74, 'publish' => true])->assertOk()->assertJsonPath('data.status', 'published');
    Notification::assertSentTo($student, GradePublishedNotice::class);
    $view = $this->actingAs($student)->getJson("/api/v1/learn/assignments/{$a->id}")->json('data.my_submissions.0');
    expect($view['grade']['final_score'])->toEqual(74)->and($view['status'])->toBe('graded');
    expect(SubmissionGrade::first()->feedback)->not->toContain('script');
    expect(GradeChangeLog::count())->toBe(0);   // pre-publication edits are not "amendments"
});

it('applies the late penalty to the final mark and keeps the raw mark', function () {
    [$lect, $student, , $a] = assignmentSetup($this, ['late_policy' => 'penalty', 'late_penalty_percent' => 10]);
    $sub = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $student->id, 'attempt' => 1, 'submitted_at' => now(), 'is_late' => true, 'late_minutes' => 60, 'text_response' => 'x']);
    asLecturer($this, $lect);
    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 80, 'publish' => true])->assertOk()->assertJsonPath('data.raw_score', 80)->assertJsonPath('data.final_score', 72);
});

it('never silently overwrites a published grade: amendments need a reason and leave an immutable trail', function () {
    Notification::fake();
    [$lect, $student, , $a] = assignmentSetup($this);
    $sub = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $student->id, 'attempt' => 1, 'text_response' => 'x', 'submitted_at' => now()]);
    asLecturer($this, $lect);
    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 60, 'feedback' => 'ok', 'publish' => true])->assertOk();

    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 85])->assertUnprocessable()->assertJsonPath('code', 'amendment_reason_required');
    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 85, 'reason' => 'no'])->assertUnprocessable();
    expect(SubmissionGrade::first()->final_score)->toEqual(60);

    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 85, 'reason' => 'Missed page 3 on first marking'])->assertOk()->assertJsonPath('data.revision', 2);
    $log = GradeChangeLog::firstOrFail();
    expect($log->previous_score)->toEqual(60)->and($log->new_score)->toEqual(85)->and($log->reason)->toBe('Missed page 3 on first marking')->and($log->changed_by)->toBe($lect->id);
    expect(AuditLog::where('action', 'grade.amended')->count())->toBe(1);
    Notification::assertSentToTimes($student, GradePublishedNotice::class, 2);
    $this->getJson("/api/v1/manage/submissions/{$sub->id}")->assertJsonPath('data.grade_history.0.new_score', 85);

    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 85, 'reason' => 'Same score resubmitted'])->assertOk();
    expect(GradeChangeLog::count())->toBe(1);   // a no-op is not recorded as an amendment

    expect(fn () => $log->update(['new_score' => 100]))->toThrow(Illuminate\Database\QueryException::class);   // trail is immutable
    expect(fn () => GradeChangeLog::query()->delete())->toThrow(Illuminate\Database\QueryException::class);
});

it('grades by rubric: every criterion required, bounded, and summed to the total', function () {
    [$lect, $student, $course, $a] = assignmentSetup($this, ['max_score' => 100]);
    $r = GradingRubric::create(['course_id' => $course->id, 'title' => 'Essay rubric']);
    $c1 = $r->criteria()->create(['title' => 'Argument', 'max_points' => 60, 'position' => 1]);
    $c2 = $r->criteria()->create(['title' => 'Style', 'max_points' => 40, 'position' => 2]);
    $a->update(['rubric_id' => $r->id]);
    $sub = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $student->id, 'attempt' => 1, 'text_response' => 'x', 'submitted_at' => now()]);
    asLecturer($this, $lect);
    $url = "/api/v1/manage/submissions/{$sub->id}/grade";
    $this->postJson($url, ['score' => 90])->assertUnprocessable()->assertJsonPath('code', 'criteria_required');
    $this->postJson($url, ['criterion_scores' => [$c1->id => 40]])->assertUnprocessable();
    $this->postJson($url, ['criterion_scores' => [$c1->id => 61, $c2->id => 10]])->assertUnprocessable()->assertJsonPath('code', 'score_out_of_range');
    $this->postJson($url, ['criterion_scores' => [$c1->id => 45, $c2->id => 30, 'bogus' => 5]])->assertUnprocessable();
    $this->postJson($url, ['criterion_scores' => [$c1->id => 45, $c2->id => 30.5], 'publish' => true])->assertOk()->assertJsonPath('data.final_score', 75.5);
});

it('validates that a rubric total matches the assignment maximum', function () {
    [$lect, , $course] = assignmentSetup($this);
    asLecturer($this, $lect);
    $rub = $this->postJson("/api/v1/manage/courses/{$course->id}/rubrics", ['title' => 'R', 'criteria' => [['title' => 'A', 'max_points' => 30], ['title' => 'B', 'max_points' => 30]]])->assertCreated()->assertJsonPath('data.total_points', 60);
    $this->postJson("/api/v1/manage/courses/{$course->id}/assignments", ['title' => 'Bad', 'max_score' => 100, 'rubric_id' => $rub->json('data.id')])->assertUnprocessable()->assertJsonPath('code', 'rubric_total_mismatch');
    $this->postJson("/api/v1/manage/courses/{$course->id}/assignments", ['title' => 'Good', 'max_score' => 60, 'rubric_id' => $rub->json('data.id')])->assertCreated();
});

it('isolates students from each other\'s submissions, grades and files', function () {
    [$lect, $alice, , $a] = assignmentSetup($this);
    $bob = $this->makeUser();
    CourseEnrollment::create(['course_id' => $a->course_id, 'user_id' => $bob->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $this->actingAs($alice)->post("/api/v1/learn/assignments/{$a->id}/submissions", ['text_response' => 'alice work', 'files' => [pdf()]], ['Accept' => 'application/json'])->assertCreated();
    $file = App\Models\SubmissionFile::firstOrFail();
    $sub = AssignmentSubmission::firstOrFail();

    $this->actingAs($bob)->getJson("/api/v1/learn/assignments/{$a->id}")->assertJsonCount(0, 'data.my_submissions');
    $this->get("/api/v1/learn/submission-files/{$file->id}", ['Accept' => 'application/json'])->assertNotFound();
    $this->getJson("/api/v1/manage/submissions/{$sub->id}")->assertForbidden();
    $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 100, 'publish' => true])->assertForbidden();   // students cannot grade themselves or peers
    $this->actingAs($alice)->get("/api/v1/learn/submission-files/{$file->id}", ['Accept' => 'application/json'])->assertOk()->assertDownload();
    expect(SubmissionGrade::count())->toBe(0);
});

it('keeps other lecturers and finance officers out of grading', function () {
    [$lect, $student, , $a] = assignmentSetup($this);
    $sub = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $student->id, 'attempt' => 1, 'text_response' => 'x', 'submitted_at' => now()]);
    foreach (['lecturer', 'finance'] as $role) {
        asLecturer($this, $this->makeUser($role));
        $this->getJson("/api/v1/manage/assignments/{$a->id}/submissions")->assertForbidden();
        $this->getJson("/api/v1/manage/submissions/{$sub->id}")->assertForbidden();
        $this->postJson("/api/v1/manage/submissions/{$sub->id}/grade", ['score' => 1])->assertForbidden();
    }
    asLecturer($this, $lect);
    $this->get("/api/v1/manage/submission-files/".App\Models\SubmissionFile::create(['submission_id' => $sub->id, 'path' => 'x', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64)])->id, ['Accept' => 'application/json'])->assertNotFound();   // owner may fetch; missing blob is a 404, not a 500
});

it('lists submissions with filters for the grading queue', function () {
    [$lect, $student, , $a] = assignmentSetup($this);
    $other = $this->makeUser();
    $s1 = AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $student->id, 'attempt' => 1, 'text_response' => 'x', 'submitted_at' => now()]);
    AssignmentSubmission::create(['assignment_id' => $a->id, 'user_id' => $other->id, 'attempt' => 1, 'text_response' => 'y', 'submitted_at' => now()]);
    asLecturer($this, $lect);
    $this->postJson("/api/v1/manage/submissions/{$s1->id}/grade", ['score' => 50, 'publish' => true])->assertOk();
    $this->getJson("/api/v1/manage/assignments/{$a->id}/submissions?status=ungraded")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/manage/assignments/{$a->id}/submissions?status=graded")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/manage/courses/{$a->course_id}/assignments")->assertOk()->assertJsonPath('data.0.ungraded_count', 1);
});
