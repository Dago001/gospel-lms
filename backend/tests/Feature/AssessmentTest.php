<?php

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentResponse;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Notifications\AssessmentResultNotice;
use App\Services\AssessmentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

afterEach(fn () => Carbon::setTestNow());

function mkQuestion(QuestionBank $bank, string $type, array $o = []): Question
{
    $q = Question::create(['bank_id' => $bank->id, 'type' => $type, 'prompt' => $o['prompt'] ?? "Q ($type)", 'points' => $o['points'] ?? 10, 'difficulty' => $o['difficulty'] ?? 'medium', 'category' => $o['category'] ?? null,
        'accepted_answers' => $o['accepted'] ?? null, 'partial_credit' => $o['partial'] ?? false, 'explanation' => $o['explanation'] ?? 'Because.']);
    $opts = $o['options'] ?? match ($type) { 'mcq' => [['A', true], ['B', false], ['C', false]], 'tf' => [['True', true], ['False', false]], 'multi' => [['A', true], ['B', true], ['C', false], ['D', false]], default => [] };
    foreach ($opts as $i => [$label, $ok]) {
        $q->options()->create(['label' => $label, 'is_correct' => $ok, 'position' => $i + 1]);
    }

    return $q->load('options');
}

function optId(Question $q, string $label): string
{
    return $q->options->firstWhere('label', $label)->id;
}

/** [lecturer, student, course, bank, assessment] with the student enrolled. */
function examSetup($test, array $assess = [], array $types = ['mcq', 'multi', 'short', 'tf']): array
{
    $lect = $test->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $bank = QuestionBank::create(['course_id' => $course->id, 'title' => 'Bank']);
    $qs = collect($types)->map(fn ($t) => mkQuestion($bank, $t, ['accepted' => $t === 'short' ? ['Paul', 'The Apostle Paul'] : null]));
    $a = Assessment::factory()->create(['course_id' => $course->id] + $assess);
    $a->fixedQuestions()->sync($qs->values()->mapWithKeys(fn ($q, $i) => [$q->id => ['position' => $i + 1]])->all());
    $student = $test->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    return [$lect, $student, $course, $bank, $a, $qs];
}

function startAttempt($test, $student, Assessment $a): array
{
    $r = $test->actingAs($student)->postJson("/api/v1/learn/assessments/{$a->id}/attempts");

    return [$r, $r->json('data.id')];
}

function answer($test, string $attemptId, Question $q, array $body)
{
    return $test->putJson("/api/v1/learn/attempts/{$attemptId}/answers/{$q->id}", $body);
}

it('never sends answer keys, explanations or accepted answers to a student taking the exam', function () {
    [, $student, , , $a] = examSetup($this);
    [$r] = startAttempt($this, $student, $a);
    $r->assertCreated();
    $json = json_encode($r->json());
    expect($json)->not->toContain('is_correct')->not->toContain('accepted_answers')->not->toContain('explanation')->not->toContain('The Apostle Paul')->not->toContain('Because.');
    expect($r->json('data.questions'))->toHaveCount(4)->and($r->json('data.seconds_remaining'))->toBeBetween(1790, 1800);
});

it('marks every supported auto-gradable type correctly and totals the percentage', function () {
    Notification::fake();
    [, $student, , , $a, $qs] = examSetup($this);
    [$mcq, $multi, $short, $tf] = $qs->all();
    [, $id] = startAttempt($this, $student, $a);
    answer($this, $id, $mcq, ['option_ids' => [optId($mcq, 'A')]])->assertOk();
    answer($this, $id, $multi, ['option_ids' => [optId($multi, 'A'), optId($multi, 'B')]])->assertOk();
    answer($this, $id, $short, ['text' => '  the   apostle PAUL '])->assertOk();            // whitespace/case-insensitive
    answer($this, $id, $tf, ['option_ids' => [optId($tf, 'False')]])->assertOk();             // wrong
    $this->postJson("/api/v1/learn/attempts/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'graded')->assertJsonPath('data.auto_submitted', false);
    $at = AssessmentAttempt::find($id);
    expect($at->total_score)->toEqual(30)->and($at->max_score)->toEqual(40)->and($at->percent)->toEqual(75)->and($at->passed)->toBeTrue()->and($at->results_published_at)->not->toBeNull();
    $res = $this->getJson("/api/v1/learn/attempts/{$id}/result")->assertOk()->assertJsonPath('data.percent', 75);
    expect(json_encode($res->json()))->not->toContain('correct_option_ids');   // review_policy = never
    Notification::assertSentTo($student, AssessmentResultNotice::class);
});

it('scores multiple-select all-or-nothing by default and proportionally when partial credit is on', function () {
    [, $student, , $bank, $a, $qs] = examSetup($this, [], ['multi']);
    [$multi] = $qs->all();
    $svc = app(AssessmentService::class);
    $snap = fn ($partial) => ['type' => 'multi', 'points' => 10.0, 'partial_credit' => $partial, 'options' => $multi->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'is_correct' => $o->is_correct])->all()];
    $A = optId($multi, 'A'); $B = optId($multi, 'B'); $C = optId($multi, 'C');
    expect($svc->mark($snap(false), ['option_ids' => [$A, $B]]))->toBe([true, 10.0])
        ->and($svc->mark($snap(false), ['option_ids' => [$A]]))->toBe([false, 0.0])
        ->and($svc->mark($snap(true), ['option_ids' => [$A]]))->toBe([false, 5.0])
        ->and($svc->mark($snap(true), ['option_ids' => [$A, $C]]))->toBe([false, 0.0])    // a wrong pick cancels credit
        ->and($svc->mark($snap(true), ['option_ids' => [$A, $B, $C]]))->toBe([false, 5.0])
        ->and($svc->mark($snap(true), []))->toBe([false, 0.0]);
});

it('routes essays to the lecturer, validates marks, and only then produces a result', function () {
    Notification::fake();
    [$lect, $student, , , $a, $qs] = examSetup($this, [], ['mcq', 'essay']);
    [$mcq, $essay] = $qs->all();
    [, $id] = startAttempt($this, $student, $a);
    answer($this, $id, $mcq, ['option_ids' => [optId($mcq, 'A')]]);
    answer($this, $id, $essay, ['text' => 'A long reflection on grace.']);
    $this->postJson("/api/v1/learn/attempts/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'grading');
    $this->getJson("/api/v1/learn/attempts/{$id}/result")->assertStatus(409)->assertJsonPath('code', 'results_pending');

    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $url = "/api/v1/manage/attempts/{$id}/responses/{$essay->id}/mark";
    $this->putJson($url, ['points' => 11])->assertUnprocessable()->assertJsonPath('code', 'score_out_of_range');
    $this->putJson($url, ['points' => -1])->assertUnprocessable();
    $this->putJson($url, ['points' => 8, 'feedback' => 'Good depth.'])->assertOk()->assertJsonPath('data.status', 'graded')->assertJsonPath('data.percent', 90);
    Notification::assertSentTo($student, AssessmentResultNotice::class);
    $this->actingAs($student)->getJson("/api/v1/learn/attempts/{$id}/result")->assertOk()->assertJsonPath('data.review.1.feedback', 'Good depth.');
});

it('holds results back when release is manual, then publishes them on request', function () {
    [$lect, $student, , , $a, $qs] = examSetup($this, ['results_release' => 'manual'], ['mcq']);
    [, $id] = startAttempt($this, $student, $a);
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'A')]]);
    $this->postJson("/api/v1/learn/attempts/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'awaiting_results');
    $this->getJson("/api/v1/learn/attempts/{$id}/result")->assertStatus(409);
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/assessments/{$a->id}/publish-results")->assertOk()->assertJsonPath('data.published', 1);
    $this->actingAs($student)->getJson("/api/v1/learn/attempts/{$id}/result")->assertOk()->assertJsonPath('data.passed', true);
});

it('reveals correct answers only according to the review policy', function () {
    foreach (['never' => false, 'immediate' => true] as $policy => $shown) {
        [, $student, , , $a, $qs] = examSetup($this, ['review_policy' => $policy], ['mcq']);
        [, $id] = startAttempt($this, $student, $a);
        $this->postJson("/api/v1/learn/attempts/{$id}/submit");
        $json = json_encode($this->getJson("/api/v1/learn/attempts/{$id}/result")->json());
        expect(str_contains($json, 'correct_option_ids'))->toBe($shown);
    }
});

it('runs the clock on the server: deadline is fixed at start and late saves are refused', function () {
    [, $student, , , $a, $qs] = examSetup($this, ['time_limit_minutes' => 10], ['mcq', 'tf']);
    Carbon::setTestNow(now());
    [$r, $id] = startAttempt($this, $student, $a);
    expect(Carbon::parse($r->json('data.deadline_at'))->timestamp - now()->timestamp)->toBe(600);   // fixed at start: 10 minutes
    Carbon::setTestNow(now()->addMinutes(9));
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'A')]])->assertOk();                 // inside the window
    Carbon::setTestNow(now()->addMinutes(2));                                                         // 11 minutes in
    answer($this, $id, $qs[1], ['option_ids' => [optId($qs[1], 'True')]])->assertStatus(409)->assertJsonPath('code', 'attempt_expired');
    $at = AssessmentAttempt::find($id);
    expect($at->status)->toBe('graded')->and($at->auto_submitted)->toBeTrue()->and($at->total_score)->toEqual(10)->and($at->submitted_at->lte($at->deadline_at))->toBeTrue();
    $this->postJson("/api/v1/learn/attempts/{$id}/submit")->assertOk();                               // idempotent
    answer($this, $id, $qs[1], ['option_ids' => [optId($qs[1], 'True')]])->assertStatus(409)->assertJsonPath('code', 'attempt_closed');
});

it('allows a short grace for a save sent just before the deadline', function () {
    [, $student, , , $a, $qs] = examSetup($this, ['time_limit_minutes' => 5], ['mcq']);
    Carbon::setTestNow(now());
    [, $id] = startAttempt($this, $student, $a);
    Carbon::setTestNow(now()->addMinutes(5)->addSeconds(5));
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'A')]])->assertOk();
});

it('closes abandoned attempts via the scheduler even if the browser never returns', function () {
    [, $student, , , $a, $qs] = examSetup($this, ['time_limit_minutes' => 5], ['mcq']);
    Carbon::setTestNow(now());
    [, $id] = startAttempt($this, $student, $a);
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'A')]]);
    Carbon::setTestNow(now()->addMinutes(6));
    expect(app(AssessmentService::class)->expireDue())->toBe(1)->and(app(AssessmentService::class)->expireDue())->toBe(0);
    expect(AssessmentAttempt::find($id))->status->toBe('graded')->total_score->toEqual(10);
});

it('never lets an attempt run past the assessment closing time', function () {
    [, $student, , , $a] = examSetup($this, ['time_limit_minutes' => 120, 'closes_at' => now()->addMinutes(20)]);
    [$r] = startAttempt($this, $student, $a);
    expect(Carbon::parse($r->json('data.deadline_at'))->diffInMinutes(now()))->toBeLessThanOrEqual(20);
});

it('persists answers incrementally and resumes after a dropped connection, same attempt and same question order', function () {
    [, $student, , , $a, $qs] = examSetup($this, ['shuffle_questions' => true, 'shuffle_options' => true], ['mcq', 'mcq', 'mcq', 'multi', 'tf']);
    [$r, $id] = startAttempt($this, $student, $a);
    $order = collect($r->json('data.questions'))->pluck('question_id')->all();
    $first = $qs->firstWhere('id', $order[0]);
    answer($this, $id, $first, ['option_ids' => [$first->options->first()->id]])->assertOk();
    answer($this, $id, $first, ['option_ids' => [$first->options->last()->id]])->assertOk();      // overwrite = upsert, not a duplicate
    expect(AssessmentResponse::where('attempt_id', $id)->count())->toBe(1);

    $again = $this->postJson("/api/v1/learn/assessments/{$a->id}/attempts")->assertOk();           // "start" again after reload
    expect($again->json('data.id'))->toBe($id)->and(collect($again->json('data.questions'))->pluck('question_id')->all())->toBe($order)
        ->and($again->json('data.questions.0.answer.option_ids'))->toBe([$first->options->last()->id]);
    expect(AssessmentAttempt::count())->toBe(1);
    $this->getJson("/api/v1/learn/attempts/{$id}")->assertOk()->assertJsonPath('data.questions.0.answer.option_ids.0', $first->options->last()->id);
});

it('enforces attempt limits, supports lecturer-granted retakes, and cannot be raced into extra attempts', function () {
    [$lect, $student, , , $a, $qs] = examSetup($this, ['max_attempts' => 1], ['mcq']);
    [, $id] = startAttempt($this, $student, $a);
    $this->postJson("/api/v1/learn/attempts/{$id}/submit");
    $this->postJson("/api/v1/learn/assessments/{$a->id}/attempts")->assertStatus(409)->assertJsonPath('code', 'attempt_limit_reached');

    $this->actingAs($lect)->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/assessments/{$a->id}/grant-attempts", ['user_id' => $student->id, 'extra_attempts' => 1, 'reason' => 'Power outage during exam'])->assertCreated();
    $this->actingAs($student)->postJson("/api/v1/learn/assessments/{$a->id}/attempts")->assertCreated()->assertJsonPath('data.attempt_no', 2);
    expect(AuditLog::where('action', 'assessment.attempts_granted')->exists())->toBeTrue();
    // The database itself refuses a second live attempt.
    expect(fn () => AssessmentAttempt::create(['assessment_id' => $a->id, 'assessment_version' => 1, 'user_id' => $student->id, 'attempt_no' => 3, 'status' => 'in_progress', 'started_at' => now(), 'question_set' => [], 'max_score' => 1]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('keeps attempts private: no one else can read, answer or submit another learner\'s attempt', function () {
    [, $alice, , , $a, $qs] = examSetup($this, [], ['mcq']);
    $bob = $this->makeUser();
    CourseEnrollment::create(['course_id' => $a->course_id, 'user_id' => $bob->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    [, $id] = startAttempt($this, $alice, $a);
    $this->actingAs($bob);
    $this->getJson("/api/v1/learn/attempts/{$id}")->assertNotFound();
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'A')]])->assertNotFound();
    $this->postJson("/api/v1/learn/attempts/{$id}/submit")->assertNotFound();
    $this->getJson("/api/v1/learn/attempts/{$id}/result")->assertNotFound();
    $this->getJson("/api/v1/manage/attempts/{$id}")->assertForbidden();
});

it('rejects answers for questions outside the attempt and options that do not belong', function () {
    [, $student, $course, $bank, $a, $qs] = examSetup($this, [], ['mcq']);
    $foreign = mkQuestion($bank, 'mcq');
    [, $id] = startAttempt($this, $student, $a);
    answer($this, $id, $foreign, ['option_ids' => [optId($foreign, 'A')]])->assertUnprocessable()->assertJsonPath('code', 'unknown_question');
    answer($this, $id, $qs[0], ['option_ids' => [optId($foreign, 'A')]])->assertUnprocessable()->assertJsonPath('code', 'invalid_answer');
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'A'), optId($qs[0], 'B')]])->assertUnprocessable();   // two picks on a single-choice
});

it('freezes each attempt: later edits to a question do not change what a learner was asked or how it is marked', function () {
    [$lect, $student, , , $a, $qs] = examSetup($this, [], ['mcq']);
    [, $id] = startAttempt($this, $student, $a);
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'A')]]);

    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $this->putJson("/api/v1/manage/questions/{$qs[0]->id}", ['type' => 'mcq', 'prompt' => 'Changed', 'options' => [['label' => 'X', 'is_correct' => true], ['label' => 'A', 'is_correct' => false]]])->assertOk()->assertJsonPath('data.version', 2);
    $this->actingAs($student)->postJson("/api/v1/learn/attempts/{$id}/submit");
    expect(AssessmentAttempt::find($id)->total_score)->toEqual(10)->and(AssessmentAttempt::find($id)->question_set[0]['prompt'])->not->toBe('Changed');
});

it('draws random questions from pools without repeats and refuses pools that are too small', function () {
    $lect = $this->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $bank = QuestionBank::create(['course_id' => $course->id, 'title' => 'Pool']);
    foreach (range(1, 6) as $i) {
        mkQuestion($bank, 'mcq', ['difficulty' => $i <= 4 ? 'easy' : 'hard', 'prompt' => "P$i"]);
    }
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $tooBig = $this->postJson("/api/v1/manage/courses/{$course->id}/assessments", ['kind' => 'quiz', 'title' => 'Big', 'pools' => [['bank_id' => $bank->id, 'count' => 3, 'difficulty' => 'hard']]])->assertCreated();
    $this->postJson("/api/v1/manage/assessments/{$tooBig->json('data.id')}/publish")->assertUnprocessable()->assertJsonPath('code', 'assessment_not_ready');
    $ok = $this->postJson("/api/v1/manage/courses/{$course->id}/assessments", ['kind' => 'quiz', 'title' => 'Draw', 'time_limit_minutes' => 20, 'pools' => [['bank_id' => $bank->id, 'count' => 3, 'difficulty' => 'easy']]])->assertCreated();
    $this->postJson("/api/v1/manage/assessments/{$ok->json('data.id')}/publish")->assertOk();

    $sets = [];
    foreach (range(1, 6) as $_) {
        $s = $this->makeUser();
        CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $s->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
        $q = collect(startAttempt($this, $s, Assessment::find($ok->json('data.id')))[0]->json('data.questions'))->pluck('question_id');
        expect($q)->toHaveCount(3)->and($q->unique())->toHaveCount(3);
        $sets[] = $q->sort()->implode(',');
    }
    expect(count(array_unique($sets)))->toBeGreaterThan(1);   // not everyone gets the same draw
});

it('validates question structure so no question can be unanswerable or ambiguous', function () {
    $lect = $this->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $bank = QuestionBank::create(['course_id' => $course->id, 'title' => 'B']);
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $url = "/api/v1/manage/question-banks/{$bank->id}/questions";
    $this->postJson($url, ['type' => 'mcq', 'prompt' => 'x', 'options' => [['label' => 'a', 'is_correct' => true], ['label' => 'b', 'is_correct' => true]]])->assertUnprocessable()->assertJsonPath('code', 'invalid_question');
    $this->postJson($url, ['type' => 'mcq', 'prompt' => 'x', 'options' => [['label' => 'a', 'is_correct' => true]]])->assertUnprocessable();
    $this->postJson($url, ['type' => 'multi', 'prompt' => 'x', 'options' => [['label' => 'a'], ['label' => 'b']]])->assertUnprocessable();
    $this->postJson($url, ['type' => 'short', 'prompt' => 'x'])->assertUnprocessable();
    $this->postJson($url, ['type' => 'tf', 'prompt' => 'Sky is blue', 'true_is_correct' => true])->assertCreated()->assertJsonCount(2, 'data.options');
    $this->postJson($url, ['type' => 'essay', 'prompt' => '<p>Discuss</p><script>x</script>'])->assertCreated();
    expect(Question::where('type', 'essay')->first()->prompt)->not->toContain('script');
});

it('requires a reason to change a published result and records before/after in the audit log', function () {
    Notification::fake();
    [$lect, $student, , , $a, $qs] = examSetup($this, [], ['essay']);
    [, $id] = startAttempt($this, $student, $a);
    answer($this, $id, $qs[0], ['text' => 'essay']);
    $this->postJson("/api/v1/learn/attempts/{$id}/submit");
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $url = "/api/v1/manage/attempts/{$id}/responses/{$qs[0]->id}/mark";
    $this->putJson($url, ['points' => 4])->assertOk();
    $this->putJson($url, ['points' => 9])->assertUnprocessable()->assertJsonPath('code', 'amendment_reason_required');
    expect(AssessmentAttempt::find($id)->total_score)->toEqual(4);
    $this->putJson($url, ['points' => 9, 'reason' => 'Re-marked against rubric'])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.passed', true);
    $log = AuditLog::where('action', 'assessment.amended')->firstOrFail();
    expect($log->reason)->toBe('Re-marked against rubric')->and($log->old_values['total'])->toEqual(4)->and($log->new_values['total'])->toEqual(9)->and($log->actor_id)->toBe($lect->id);
    Notification::assertSentToTimes($student, AssessmentResultNotice::class, 2);
});

it('limits supplementary assessments to learners who failed the original', function () {
    [$lect, $student, $course, $bank, $orig, $qs] = examSetup($this, ['kind' => 'exam', 'max_attempts' => 1], ['mcq']);
    $sup = Assessment::factory()->create(['course_id' => $course->id, 'kind' => 'supplementary', 'parent_assessment_id' => $orig->id]);
    $sup->fixedQuestions()->sync([mkQuestion($bank, 'mcq')->id => ['position' => 1]]);
    $this->actingAs($student)->postJson("/api/v1/learn/assessments/{$sup->id}/attempts")->assertForbidden()->assertJsonPath('code', 'not_eligible_supplementary');   // has not sat the original
    [, $id] = startAttempt($this, $student, $orig);
    answer($this, $id, $qs[0], ['option_ids' => [optId($qs[0], 'B')]]);                                    // wrong
    $this->postJson("/api/v1/learn/attempts/{$id}/submit");
    expect(AssessmentAttempt::find($id)->passed)->toBeFalse();
    $this->postJson("/api/v1/learn/assessments/{$sup->id}/attempts")->assertCreated();
});

it('requires all lessons to be finished when the assessment says so', function () {
    [, $student, $course, , $a] = examSetup($this, ['require_all_lessons' => true]);
    $this->actingAs($student)->postJson("/api/v1/learn/assessments/{$a->id}/attempts")->assertStatus(423)->assertJsonPath('code', 'lessons_incomplete');
    App\Models\LessonCompletionRecord::create(['user_id' => $student->id, 'lesson_id' => $course->lessons()->first()->id, 'course_id' => $course->id, 'method' => 'reading', 'completed_at' => now()]);
    $this->postJson("/api/v1/learn/assessments/{$a->id}/attempts")->assertCreated();
});

it('enforces the opening window, enrolment and unpublished state', function () {
    [, $student, , , $future] = examSetup($this, ['opens_at' => now()->addDay()]);
    $this->actingAs($student)->postJson("/api/v1/learn/assessments/{$future->id}/attempts")->assertStatus(409)->assertJsonPath('code', 'not_open');
    [, $s2, , , $closed] = examSetup($this, ['opens_at' => now()->subDays(3), 'closes_at' => now()->subDay()]);
    $this->actingAs($s2)->postJson("/api/v1/learn/assessments/{$closed->id}/attempts")->assertStatus(409)->assertJsonPath('code', 'closed');
    [, , , , $open] = examSetup($this);
    $this->actingAs($this->makeUser())->postJson("/api/v1/learn/assessments/{$open->id}/attempts")->assertForbidden();
    [, $s3, , , $draft] = examSetup($this, ['status' => 'draft']);
    $this->actingAs($s3)->postJson("/api/v1/learn/assessments/{$draft->id}/attempts")->assertNotFound();
});

it('keeps lecturers of other courses, students and finance out of assessment management', function () {
    [$lect, $student, $course, $bank, $a, $qs] = examSetup($this);
    foreach (['student', 'finance', 'lecturer'] as $role) {
        $this->actingAs($this->makeUser($role))->withSession(['mfa_verified' => true]);
        $this->getJson("/api/v1/manage/assessments/{$a->id}")->assertForbidden();
        $this->getJson("/api/v1/manage/question-banks/{$bank->id}/questions")->assertForbidden();
        $this->postJson("/api/v1/manage/assessments/{$a->id}/publish-results")->assertForbidden();
        $this->putJson("/api/v1/manage/questions/{$qs[0]->id}", ['type' => 'essay', 'prompt' => 'hijack'])->assertForbidden();
    }
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->getJson("/api/v1/manage/assessments/{$a->id}/attempts")->assertOk();
});
