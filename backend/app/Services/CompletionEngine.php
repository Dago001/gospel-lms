<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseCompletionCheck;
use App\Models\CourseEnrollment;
use App\Models\LessonCompletionRecord;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\CourseCompletedNotice;
use App\Services\Video\ProgressionService;
use Illuminate\Support\Facades\DB;

/**
 * The only place that decides whether a learner has completed a course.
 * Inputs are stored backend facts (completed lessons, published grades, released assessment results, attendance); nothing
 * from the browser. Every required component must pass on its own: a high weighted average never rescues a failed
 * mandatory component such as a final examination.
 */
class CompletionEngine
{
    public const DEFAULT_POLICY = ['course_pass_percent' => 50, 'attempt_selection' => 'best', 'require_lessons' => true, 'min_attendance_percent' => null,
        'lecturer_approval_required' => false, 'show_grade_on_certificate' => false];

    public function __construct(private ProgressionService $progression, private CertificateService $certificates, private AuditLogger $audit) {}

    public function policy(Course $course): array
    {
        return array_merge(self::DEFAULT_POLICY, $course->completion_policy ?? []);
    }

    /** Weighted items must add up to exactly 100. Empty list means the policy is valid. */
    public function weightProblems(Course $course): array
    {
        $a = Assignment::where('course_id', $course->id)->where('status', 'published')->sum('weight');
        $q = Assessment::where('course_id', $course->id)->where('status', 'published')->where('kind', '!=', 'supplementary')->sum('weight');
        $sum = round((float) $a + (float) $q, 2);

        return ($sum > 0 && abs($sum - 100) > 0.01) ? ["Assessment weights add up to $sum%, but must add up to 100%."] : [];
    }

    /** Pure evaluation. @return array{eligible:bool, grade_percent:?float, reasons:array, components:array} */
    public function evaluate(User $user, Course $course, ?CourseCompletionCheck $existing = null): array
    {
        $p = $this->policy($course);
        $reasons = [];
        $components = [];
        $fail = function (string $code, string $message, array $extra = []) use (&$reasons) {
            $reasons[] = ['code' => $code, 'message' => $message] + $extra;
        };

        $enrol = CourseEnrollment::where(['course_id' => $course->id, 'user_id' => $user->id])->first();
        if (! $enrol || ! in_array($enrol->status, ['active', 'completed'], true)) {
            $fail('enrolment_inactive', 'Your enrolment in this course is not active.');

            return ['eligible' => false, 'grade_percent' => null, 'reasons' => $reasons, 'components' => []];
        }

        // ---- lessons & materials
        $required = $this->progression->ordered($course)->where('is_required', true);
        if ($p['require_lessons'] && $required->isNotEmpty()) {
            $done = LessonCompletionRecord::where(['user_id' => $user->id, 'course_id' => $course->id])->pluck('lesson_id')->all();
            $missing = $required->reject(fn ($l) => in_array($l->id, $done, true));
            $components[] = ['type' => 'lessons', 'completed' => $required->count() - $missing->count(), 'required' => $required->count()];
            if ($missing->isNotEmpty()) {
                $fail('lessons_incomplete', $missing->count().' required lesson(s) still to complete.', ['items' => $missing->take(5)->pluck('title')->values()->all()]);
            }
            $pendingAck = DB::table('lesson_resources')->whereIn('lesson_id', $required->pluck('id'))->where('requires_acknowledgement', true)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('resource_acknowledgements')->whereColumn('resource_acknowledgements.resource_id', 'lesson_resources.id')->where('user_id', $user->id))->count();
            if ($pendingAck > 0) {
                $fail('acknowledgements_pending', "$pendingAck required study material(s) still to be acknowledged.");
            }
        }

        // ---- graded components
        $weighted = [];
        $allResultsPresent = true;

        foreach (Assignment::where('course_id', $course->id)->where('status', 'published')->get() as $a) {
            if (! $a->is_required && $a->weight <= 0) {
                continue;
            }
            $subs = AssignmentSubmission::where(['assignment_id' => $a->id, 'user_id' => $user->id])->with('grade')->orderBy('attempt')->get();
            $graded = $subs->filter(fn ($s) => $s->grade && $s->grade->status === 'published');
            $pick = $graded->isEmpty() ? null : ($p['attempt_selection'] === 'latest' ? $graded->last() : $graded->sortByDesc(fn ($s) => $s->grade->final_score)->first());
            $percent = $pick ? round($pick->grade->final_score / $a->max_score * 100, 2) : null;
            $passed = $pick ? ($a->pass_mark === null || $pick->grade->final_score >= $a->pass_mark) : null;
            $status = $pick ? ($passed ? 'passed' : 'failed') : ($subs->isEmpty() ? 'not_submitted' : 'awaiting_grade');
            $components[] = ['type' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'weight' => $a->weight, 'required' => $a->is_required, 'status' => $status, 'percent' => $percent];
            if ($a->weight > 0) {
                $weighted[] = [$a->weight, $percent ?? 0.0];
                $allResultsPresent = $allResultsPresent && $pick !== null;
            }
            if ($a->is_required) {
                match ($status) {
                    'not_submitted' => $fail('assignment_not_submitted', "Assignment \"{$a->title}\" has not been submitted.", ['item' => $a->title]),
                    'awaiting_grade' => $fail('assignment_awaiting_grade', "Assignment \"{$a->title}\" is waiting to be graded.", ['item' => $a->title]),
                    'failed' => $fail('assignment_failed', "Assignment \"{$a->title}\" is below the pass mark.", ['item' => $a->title]),
                    default => null,
                };
            }
        }

        foreach (Assessment::where('course_id', $course->id)->where('status', 'published')->where('kind', '!=', 'supplementary')->get() as $a) {
            if (! $a->is_required && $a->weight <= 0 && ! $a->is_final_exam) {
                continue;
            }
            $family = Assessment::where('id', $a->id)->orWhere('parent_assessment_id', $a->id)->pluck('id');
            $attempts = AssessmentAttempt::whereIn('assessment_id', $family)->where('user_id', $user->id)->get();
            $released = $attempts->filter(fn ($t) => $t->results_published_at !== null && $t->status === 'graded');
            if ($released->isEmpty()) {
                $status = $attempts->isEmpty() ? 'not_taken' : 'awaiting_results';
                $percent = null;
            } elseif ($p['attempt_selection'] === 'latest') {
                $last = $released->sortBy('started_at')->last();
                [$status, $percent] = [$last->passed ? 'passed' : 'failed', $last->percent];
            } else {
                $passedOnes = $released->where('passed', true);
                if ($passedOnes->isNotEmpty()) {
                    [$status, $percent] = ['passed', $passedOnes->max('percent')];
                } else {
                    [$status, $percent] = ['failed', $released->max('percent')];
                }
            }
            $components[] = ['type' => 'assessment', 'id' => $a->id, 'title' => $a->title, 'kind' => $a->kind, 'weight' => $a->weight, 'required' => true, 'final_exam' => $a->is_final_exam, 'status' => $status, 'percent' => $percent];
            if ($a->weight > 0) {
                $weighted[] = [$a->weight, $percent ?? 0.0];
                $allResultsPresent = $allResultsPresent && $percent !== null;
            }
            $label = $a->is_final_exam ? 'The final examination' : "\"{$a->title}\"";
            match ($status) {
                'not_taken' => $fail($a->is_final_exam ? 'final_exam_not_taken' : 'assessment_not_taken', "$label has not been taken.", ['item' => $a->title]),
                'awaiting_results' => $fail('assessment_awaiting_results', "$label is waiting for its result.", ['item' => $a->title]),
                'failed' => $fail($a->is_final_exam ? 'final_exam_failed' : 'assessment_failed', "$label must be passed on its own; it is below the pass mark.", ['item' => $a->title]),
                default => null,
            };
        }

        // ---- attendance
        if ($p['min_attendance_percent'] !== null) {
            $sessions = LiveSession::where('course_id', $course->id)->where('counts_for_attendance', true)->where('status', '!=', 'cancelled')->get();
            if ($sessions->isNotEmpty()) {
                $open = $sessions->filter(fn ($s) => $s->endsAt()->isFuture());
                $heldIds = $sessions->reject(fn ($s) => $s->endsAt()->isFuture())->pluck('id');
                $present = DB::table('live_session_attendance')->whereIn('live_session_id', $heldIds)->where('user_id', $user->id)->where('present', true)->count();
                $pct = $heldIds->count() ? round($present / $heldIds->count() * 100, 1) : 0;
                $components[] = ['type' => 'attendance', 'attended' => $present, 'held' => $heldIds->count(), 'percent' => $pct, 'required_percent' => $p['min_attendance_percent']];
                if ($open->isNotEmpty()) {
                    $fail('live_sessions_remaining', $open->count().' live session(s) still to be held.');
                } elseif ($pct < $p['min_attendance_percent']) {
                    $fail('attendance_too_low', "Attendance is {$pct}% but {$p['min_attendance_percent']}% is required.");
                }
            }
        }

        // ---- weighted course grade
        $grade = null;
        if ($weighted) {
            $total = array_sum(array_column($weighted, 0));
            $grade = round(array_sum(array_map(fn ($w) => $w[0] * $w[1], $weighted)) / $total, 2);
            if ($allResultsPresent && $grade < $p['course_pass_percent']) {
                $fail('course_grade_below_pass', "Your overall grade is {$grade}% but {$p['course_pass_percent']}% is required.");
            }
        }

        // ---- lecturer sign-off only matters once everything else is satisfied
        if (! $reasons && $p['lecturer_approval_required'] && ! $existing?->approved_by) {
            $fail('awaiting_lecturer_approval', 'Your lecturer must approve your completion.');
        }

        return ['eligible' => ! $reasons, 'grade_percent' => $grade, 'reasons' => $reasons, 'components' => $components];
    }

    /** Evaluate, record, and (once) complete the enrolment and issue the certificate. Safe to call any number of times, concurrently. */
    public function run(User $user, Course $course, string $trigger): CourseCompletionCheck
    {
        $newlyEligible = false;
        $check = DB::transaction(function () use ($user, $course, $trigger, &$newlyEligible) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['cmp'.$course->id.$user->id]);
            $check = CourseCompletionCheck::firstOrNew(['user_id' => $user->id, 'course_id' => $course->id]);
            $was = $check->exists ? $check->eligible : null;
            $r = $this->evaluate($user, $course, $check->exists ? $check : null);
            $check->fill(['eligible' => $r['eligible'], 'grade_percent' => $r['grade_percent'], 'reasons' => $r['reasons'], 'components' => $r['components'], 'trigger' => $trigger,
                'evaluated_at' => now(), 'evaluations' => ($check->evaluations ?? 0) + 1])->save();

            if ($r['eligible']) {
                $enrol = CourseEnrollment::where(['course_id' => $course->id, 'user_id' => $user->id])->lockForUpdate()->first();
                if ($enrol->status === 'active') {
                    $enrol->update(['status' => 'completed', 'completed_at' => now()]);
                    $newlyEligible = true;
                    $this->audit->log('course.completed', $enrol, new: ['grade_percent' => $r['grade_percent']], actorId: $user->id);
                }
                $this->certificates->issueIfEligible($user, $course, $check);
            } elseif ($was === true) {
                $this->audit->log('completion.regressed', $check, new: ['reasons' => array_column($r['reasons'], 'code')], actorId: $user->id);   // a certificate, if issued, is not silently revoked
            }

            return $check;
        });
        if ($newlyEligible) {
            $user->notify(new CourseCompletedNotice($course->title));
        }

        return $check;
    }
}
