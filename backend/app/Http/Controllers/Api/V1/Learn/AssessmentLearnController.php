<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssessmentLearnController extends Controller
{
    public function __construct(private AssessmentService $svc) {}

    /** Questions exactly as the learner may see them: no correctness flags, accepted answers or explanations. */
    private function attemptView(AssessmentAttempt $at): array
    {
        $at->loadMissing('responses');
        $resp = $at->responses->keyBy('question_id');

        return ['id' => $at->id, 'assessment_id' => $at->assessment_id, 'attempt_no' => $at->attempt_no, 'status' => $at->status, 'started_at' => $at->started_at->toIso8601String(),
            'deadline_at' => $at->deadline_at?->toIso8601String(), 'server_now' => now()->toIso8601String(), 'seconds_remaining' => $at->deadline_at ? max(0, now()->diffInSeconds($at->deadline_at, false)) : null,
            'questions' => collect($at->question_set)->map(fn ($q) => ['question_id' => $q['question_id'], 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => $q['points'],
                'options' => collect($q['options'])->map(fn ($o) => ['id' => $o['id'], 'label' => $o['label']])->values(), 'answer' => $resp[$q['question_id']]->answer ?? null])->values()];
    }

    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);
        $uid = $request->user()->id;
        $rows = Assessment::where('course_id', $course->id)->where('status', 'published')->orderBy('opens_at')->get()->map(function ($a) use ($uid) {
            $mine = $a->attempts()->where('user_id', $uid)->orderBy('attempt_no')->get();

            return $a->only(['id', 'kind', 'title', 'instructions', 'time_limit_minutes', 'max_attempts', 'pass_mark_percent', 'is_required', 'is_final_exam'])
                + ['opens_at' => $a->opens_at?->toIso8601String(), 'closes_at' => $a->closes_at?->toIso8601String(), 'is_open' => $a->isOpen(),
                    'attempts' => $mine->map(fn ($t) => ['id' => $t->id, 'attempt_no' => $t->attempt_no, 'status' => $t->status === 'graded' && ! $t->results_published_at ? 'awaiting_results' : $t->status,
                        'percent' => $t->results_published_at ? $t->percent : null, 'passed' => $t->results_published_at ? $t->passed : null])->values()];
        });

        return response()->json(['data' => $rows]);
    }

    public function start(Request $request, Assessment $assessment): JsonResponse
    {
        $at = $this->svc->start($request->user(), $assessment);

        return response()->json(['data' => $this->attemptView($at)], $at->wasRecentlyCreated ? 201 : 200);
    }

    private function owned(Request $request, AssessmentAttempt $at): AssessmentAttempt
    {
        abort_unless($at->user_id === $request->user()->id, 404);

        return $at;
    }

    /** Resume after a reload or lost connection. Timed-out attempts are closed here too. */
    public function show(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->owned($request, $attempt);
        if ($attempt->status === 'in_progress' && $this->svc->expired($attempt)) {
            $attempt = $this->svc->submit($attempt);
        }
        if ($attempt->status !== 'in_progress') {
            return response()->json(['data' => ['id' => $attempt->id, 'status' => $attempt->status === 'graded' && ! $attempt->results_published_at ? 'awaiting_results' : $attempt->status, 'submitted_at' => $attempt->submitted_at?->toIso8601String()]]);
        }

        return response()->json(['data' => $this->attemptView($attempt)]);
    }

    public function saveAnswer(Request $request, AssessmentAttempt $attempt, string $questionId): JsonResponse
    {
        $this->owned($request, $attempt);
        $d = $request->validate(['option_ids' => ['sometimes', 'array', 'max:10'], 'option_ids.*' => ['uuid'], 'text' => ['sometimes', 'nullable', 'string', 'max:50000']]);
        $this->svc->saveAnswer($attempt, $questionId, $d);

        return response()->json(['data' => ['saved' => true, 'server_now' => now()->toIso8601String(), 'seconds_remaining' => $attempt->deadline_at ? max(0, now()->diffInSeconds($attempt->deadline_at, false)) : null]]);
    }

    public function submit(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->owned($request, $attempt);
        $at = $this->svc->submit($attempt);

        return response()->json(['data' => ['id' => $at->id, 'status' => $at->status === 'graded' && ! $at->results_published_at ? 'awaiting_results' : $at->status, 'auto_submitted' => $at->auto_submitted]]);
    }

    /** Result and review. Hidden until released; correct answers only per the assessment's review policy. */
    public function result(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $this->owned($request, $attempt);
        $attempt->load(['assessment', 'responses']);
        if (! $attempt->results_published_at) {
            return response()->json(['message' => 'Results have not been released yet.', 'code' => 'results_pending'], 409);
        }
        $a = $attempt->assessment;
        $reveal = match ($a->review_policy) {
            'immediate' => true,
            'after_close' => $a->closes_at && $a->closes_at->lt(now()),
            default => false,
        };
        $resp = $attempt->responses->keyBy('question_id');

        return response()->json(['data' => ['total_score' => $attempt->total_score, 'max_score' => $attempt->max_score, 'percent' => $attempt->percent, 'passed' => $attempt->passed, 'pass_mark_percent' => $a->pass_mark_percent,
            'review' => collect($attempt->question_set)->map(function ($q) use ($resp, $reveal) {
                $r = $resp[$q['question_id']] ?? null;

                return ['question_id' => $q['question_id'], 'prompt' => $q['prompt'], 'type' => $q['type'], 'points_available' => $q['points'], 'points_awarded' => $r?->points(), 'your_answer' => $r?->answer, 'feedback' => $r?->feedback]
                    + ($reveal ? ['correct_option_ids' => collect($q['options'])->where('is_correct', true)->pluck('id')->values(), 'accepted_answers' => $q['accepted_answers'], 'explanation' => $q['explanation']] : []);
            })->values()]]);
    }
}
