<?php

namespace App\Services;

use App\Events\GradePublished;
use App\Exceptions\DomainException;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentResponse;
use App\Models\Question;
use App\Models\User;
use App\Notifications\AssessmentResultNotice;
use App\Services\Video\ProgressionService;
use Illuminate\Support\Facades\DB;

class AssessmentService
{
    /** Network latency allowance for a final save that was sent before the deadline. */
    public const SAVE_GRACE_SECONDS = 10;

    public function __construct(private EnrolmentService $enrolments, private ProgressionService $progression, private AuditLogger $audit) {}

    // ------------------------------------------------------------------ starting
    public function start(User $user, Assessment $a): AssessmentAttempt
    {
        $this->assertEligible($user, $a);

        return DB::transaction(function () use ($user, $a) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['asmt'.$a->id.$user->id]);
            $live = AssessmentAttempt::where(['assessment_id' => $a->id, 'user_id' => $user->id, 'status' => 'in_progress'])->first();
            if ($live) {
                if (! $this->expired($live)) {
                    return $live;                                    // resume: starting twice never costs an attempt
                }
                $this->finalize($live, true);
            }
            $used = AssessmentAttempt::where(['assessment_id' => $a->id, 'user_id' => $user->id])->count();
            $allowed = $a->max_attempts + (int) DB::table('assessment_attempt_grants')->where(['assessment_id' => $a->id, 'user_id' => $user->id])->sum('extra_attempts');
            if ($used >= $allowed) {
                throw new DomainException('You have used all attempts for this assessment.', 'attempt_limit_reached', 409);
            }
            $set = $this->buildSet($a);
            if (! $set) {
                throw new DomainException('This assessment has no questions yet.', 'assessment_empty', 409);
            }
            $now = now();
            $deadline = $a->time_limit_minutes ? $now->copy()->addMinutes($a->time_limit_minutes) : null;
            if ($a->closes_at && (! $deadline || $a->closes_at->lt($deadline))) {
                $deadline = $a->closes_at->copy();                   // never run past the closing time
            }
            $attempt = AssessmentAttempt::create(['assessment_id' => $a->id, 'assessment_version' => $a->version, 'user_id' => $user->id, 'attempt_no' => $used + 1, 'status' => 'in_progress',
                'started_at' => $now, 'deadline_at' => $deadline, 'question_set' => $set, 'max_score' => array_sum(array_column($set, 'points'))]);
            $this->audit->log('attempt.started', $attempt, new: ['assessment_id' => $a->id, 'attempt_no' => $attempt->attempt_no], actorId: $user->id);

            return $attempt;
        });
    }

    public function assertEligible(User $user, Assessment $a): void
    {
        if ($a->status !== 'published') {
            throw new DomainException('This assessment is not available.', 'assessment_unavailable', 404);
        }
        if (! $this->enrolments->userHasAccess($user, $a->course)) {
            throw new DomainException('You are not enrolled in this course.', 'not_enrolled', 403);
        }
        if ($a->opens_at && $a->opens_at->gt(now())) {
            throw new DomainException('This assessment has not opened yet.', 'not_open', 409);
        }
        if ($a->closes_at && $a->closes_at->lt(now())) {
            throw new DomainException('This assessment has closed.', 'closed', 409);
        }
        if ($a->require_all_lessons) {
            $s = $this->progression->summary($user, $a->course);
            if ($s['required_total'] > $s['required_completed']) {
                throw new DomainException('Complete all required lessons before taking this assessment.', 'lessons_incomplete', 423);
            }
        }
        if ($a->kind === 'supplementary' && $a->parent_assessment_id) {
            $passed = AssessmentAttempt::where(['assessment_id' => $a->parent_assessment_id, 'user_id' => $user->id, 'passed' => true])->exists();
            $failed = AssessmentAttempt::where(['assessment_id' => $a->parent_assessment_id, 'user_id' => $user->id])->where('passed', false)->exists();
            if ($passed || ! $failed) {
                throw new DomainException('This supplementary assessment is only for learners who did not pass the original.', 'not_eligible_supplementary', 403);
            }
        }
    }

    /** Fixed questions + random draws from pools, optionally shuffled. Frozen into the attempt. */
    private function buildSet(Assessment $a): array
    {
        $entries = [];
        $used = [];
        foreach ($a->fixedQuestions()->with('options')->get() as $q) {
            $entries[] = $this->snapshot($q, $q->pivot->points);
            $used[] = $q->id;
        }
        foreach ($a->pools ?? [] as $pool) {
            $qs = Question::with('options')->where('bank_id', $pool['bank_id'])->whereNull('archived_at')->whereNotIn('id', $used)
                ->when($pool['category'] ?? null, fn ($q, $v) => $q->where('category', $v))->when($pool['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))->get();
            if ($qs->count() < $pool['count']) {
                throw new DomainException('The question pool is too small; contact your lecturer.', 'pool_too_small', 409);
            }
            foreach ($qs->shuffle()->take($pool['count']) as $q) {
                $entries[] = $this->snapshot($q, null);
                $used[] = $q->id;
            }
        }
        if ($a->shuffle_questions) {
            shuffle($entries);
        }
        if ($a->shuffle_options) {
            foreach ($entries as &$e) {
                if (in_array($e['type'], ['mcq', 'multi'], true)) {
                    shuffle($e['options']);
                }
            }
            unset($e);
        }

        return array_values($entries);
    }

    private function snapshot(Question $q, $pointsOverride): array
    {
        return ['question_id' => $q->id, 'question_version' => $q->version, 'type' => $q->type, 'prompt' => $q->prompt, 'category' => $q->category, 'difficulty' => $q->difficulty,
            'points' => (float) ($pointsOverride ?? $q->points), 'explanation' => $q->explanation, 'accepted_answers' => $q->accepted_answers, 'case_sensitive' => $q->case_sensitive,
            'partial_credit' => $q->partial_credit, 'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'is_correct' => $o->is_correct])->all()];
    }

    // ------------------------------------------------------------------ answering
    public function expired(AssessmentAttempt $at): bool
    {
        return $at->deadline_at && $at->deadline_at->copy()->addSeconds(self::SAVE_GRACE_SECONDS)->lt(now());
    }

    /** Idempotent upsert of one answer. The clock is the server's. */
    public function saveAnswer(AssessmentAttempt $attempt, string $questionId, array $answer): AssessmentResponse
    {
        $timedOut = false;
        $saved = DB::transaction(function () use ($attempt, $questionId, $answer, &$timedOut) {
            $at = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($at->status !== 'in_progress') {
                throw new DomainException('This attempt has already been submitted.', 'attempt_closed', 409);
            }
            if ($this->expired($at)) {
                $this->finalize($at, true);     // must commit: throwing here would roll the submission back
                $timedOut = true;

                return null;
            }
            $q = collect($at->question_set)->firstWhere('question_id', $questionId);
            if (! $q) {
                throw new DomainException('That question is not part of this attempt.', 'unknown_question', 422);
            }

            return AssessmentResponse::updateOrCreate(['attempt_id' => $at->id, 'question_id' => $questionId], ['answer' => $this->cleanAnswer($q, $answer), 'saved_at' => now()]);
        });
        if ($timedOut) {
            throw new DomainException('Time is up. Your saved answers were submitted.', 'attempt_expired', 409);
        }

        return $saved;
    }

    private function cleanAnswer(array $q, array $answer): array
    {
        $ids = collect($q['options'])->pluck('id')->all();
        switch ($q['type']) {
            case 'mcq':
            case 'tf':
                $sel = array_values(array_unique((array) ($answer['option_ids'] ?? [])));
                if (count($sel) > 1 || array_diff($sel, $ids)) {
                    throw new DomainException('Choose one valid option.', 'invalid_answer');
                }

                return ['option_ids' => $sel];
            case 'multi':
                $sel = array_values(array_unique((array) ($answer['option_ids'] ?? [])));
                if (array_diff($sel, $ids)) {
                    throw new DomainException('Choose valid options.', 'invalid_answer');
                }

                return ['option_ids' => $sel];
            case 'short':
                return ['text' => mb_substr(trim((string) ($answer['text'] ?? '')), 0, 2000)];
            default:
                return ['text' => mb_substr((string) ($answer['text'] ?? ''), 0, 50000)];
        }
    }

    // ------------------------------------------------------------------ finishing
    public function submit(AssessmentAttempt $attempt): AssessmentAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $at = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($at->status === 'in_progress') {
                $this->finalize($at, $this->expired($at));
            }

            return $at->fresh();
        });
    }

    /** Close an attempt and auto-mark what can be auto-marked. Caller holds the row lock. */
    public function finalize(AssessmentAttempt $at, bool $auto): void
    {
        if ($at->status !== 'in_progress') {
            return;
        }
        $submitted = ($at->deadline_at && now()->gt($at->deadline_at)) ? $at->deadline_at : now();   // late saves within grace count; the stamp never exceeds the deadline
        $needsManual = false;
        foreach ($at->question_set as $q) {
            $r = AssessmentResponse::firstOrNew(['attempt_id' => $at->id, 'question_id' => $q['question_id']]);
            if (! $r->exists) {
                $r->saved_at = now();
            }
            [$correct, $pts] = $this->mark($q, $r->answer);
            $r->is_correct = $correct;
            $r->auto_points = $pts;
            $r->save();
            $needsManual = $needsManual || $q['type'] === 'essay';
        }
        $at->forceFill(['submitted_at' => $submitted, 'auto_submitted' => $auto, 'status' => $needsManual ? 'grading' : 'graded'])->save();
        if (! $needsManual) {
            $this->computeTotals($at);
        }
        $this->audit->log($auto ? 'attempt.auto_submitted' : 'attempt.submitted', $at, new: ['status' => $at->status], actorId: $at->user_id);
    }

    /** @return array{0:?bool,1:?float} correctness and points; essays are null/null until a lecturer marks them */
    public function mark(array $q, ?array $answer): array
    {
        $points = (float) $q['points'];
        switch ($q['type']) {
            case 'mcq':
            case 'tf':
                $correct = collect($q['options'])->where('is_correct', true)->pluck('id')->all();
                $sel = $answer['option_ids'] ?? [];
                $ok = count($sel) === 1 && in_array($sel[0], $correct, true);

                return [$ok, $ok ? $points : 0.0];
            case 'multi':
                $correct = collect($q['options'])->where('is_correct', true)->pluck('id')->all();
                $sel = $answer['option_ids'] ?? [];
                $right = count(array_intersect($sel, $correct));
                $wrong = count(array_diff($sel, $correct));
                if ($right === count($correct) && $wrong === 0) {
                    return [true, $points];
                }
                $pts = ! empty($q['partial_credit']) && $correct ? round(max(0, ($right - $wrong) / count($correct)) * $points, 2) : 0.0;

                return [false, $pts];
            case 'short':
                $text = trim(preg_replace('/\s+/', ' ', (string) ($answer['text'] ?? '')));
                $ok = false;
                foreach ($q['accepted_answers'] ?? [] as $acc) {
                    $acc = trim(preg_replace('/\s+/', ' ', (string) $acc));
                    if ($text !== '' && (! empty($q['case_sensitive']) ? $text === $acc : mb_strtolower($text) === mb_strtolower($acc))) {
                        $ok = true;
                        break;
                    }
                }

                return [$ok, $ok ? $points : 0.0];
        }

        return [null, null];     // essay
    }

    /** Sum of manual-over-auto points; sets status graded when nothing is awaiting a marker. */
    public function computeTotals(AssessmentAttempt $at): void
    {
        $at->load('assessment', 'responses');
        $pending = collect($at->question_set)->contains(function ($q) use ($at) {
            if ($q['type'] !== 'essay') {
                return false;
            }
            $r = $at->responses->firstWhere('question_id', $q['question_id']);

            return ! $r || $r->manual_points === null;     // an essay nobody has marked yet
        });
        if ($pending) {
            $at->forceFill(['status' => 'grading'])->save();

            return;
        }
        $total = round($at->responses->sum(fn ($r) => $r->points() ?? 0), 2);
        $percent = $at->max_score > 0 ? round($total / $at->max_score * 100, 2) : 0;
        $at->forceFill(['status' => 'graded', 'total_score' => $total, 'percent' => $percent, 'passed' => $percent >= $at->assessment->pass_mark_percent, 'graded_at' => $at->graded_at ?? now()])->save();
        if ($at->assessment->results_release === 'immediate' && ! $at->results_published_at) {
            $this->releaseResults($at, false);
        }
    }

    public function releaseResults(AssessmentAttempt $at, bool $amended): void
    {
        $at->forceFill(['results_published_at' => $at->results_published_at ?? now()])->save();
        $user = User::find($at->user_id);
        $title = $at->assessment->title;
        DB::afterCommit(function () use ($user, $at, $title, $amended) {
            $user->notify(new AssessmentResultNotice($title, (bool) $at->passed, $amended));
            event(new GradePublished($user->id, $at->assessment->course_id, 'assessment', $at->id));
        });
    }

    // ------------------------------------------------------------------ lecturer marking
    public function markManual(AssessmentAttempt $attempt, string $questionId, float $points, ?string $feedback, User $grader, ?string $reason): AssessmentAttempt
    {
        return DB::transaction(function () use ($attempt, $questionId, $points, $feedback, $grader, $reason) {
            $at = AssessmentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($at->status === 'in_progress') {
                throw new DomainException('The learner is still taking this assessment.', 'attempt_in_progress', 409);
            }
            $q = collect($at->question_set)->firstWhere('question_id', $questionId);
            if (! $q) {
                throw new DomainException('That question is not part of this attempt.', 'unknown_question', 422);
            }
            if ($points < 0 || $points > $q['points']) {
                throw new DomainException("Marks must be between 0 and {$q['points']}.", 'score_out_of_range');
            }
            $r = AssessmentResponse::firstOrCreate(['attempt_id' => $at->id, 'question_id' => $questionId], ['saved_at' => now()]);
            $published = (bool) $at->results_published_at;
            if ($published && mb_strlen(trim((string) $reason)) < 5) {
                throw new DomainException('A reason is required to change a published result.', 'amendment_reason_required');
            }
            $before = ['points' => $r->points(), 'total' => $at->total_score];
            $r->forceFill(['manual_points' => round($points, 2), 'feedback' => $feedback ?? $r->feedback, 'graded_by' => $grader->id])->save();
            $this->computeTotals($at);
            $at->refresh();
            if ($published) {
                $at->increment('revision');
                $this->audit->log('assessment.amended', $at, $before, ['points' => $points, 'total' => $at->total_score], $reason, $grader->id);
                $this->releaseResults($at, true);
            } else {
                $this->audit->log('assessment.marked', $at, new: ['question_id' => $questionId, 'points' => $points], actorId: $grader->id);
            }

            return $at;
        });
    }

    /** Scheduler entry: close timed-out attempts even if the learner's browser is gone. */
    public function expireDue(): int
    {
        $n = 0;
        AssessmentAttempt::where('status', 'in_progress')->whereNotNull('deadline_at')->where('deadline_at', '<', now()->subSeconds(self::SAVE_GRACE_SECONDS))->each(function ($a) use (&$n) {
            DB::transaction(function () use ($a, &$n) {
                $at = AssessmentAttempt::whereKey($a->id)->lockForUpdate()->first();
                if ($at && $at->status === 'in_progress') {
                    $this->finalize($at, true);
                    $n++;
                }
            });
        });

        return $n;
    }
}
