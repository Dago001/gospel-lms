<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Services\AssessmentService;
use App\Services\AuditLogger;
use App\Services\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function __construct(private HtmlSanitizer $html, private AuditLogger $audit, private AssessmentService $svc) {}

    // ------------------------------------------------------------ question banks
    public function banks(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => QuestionBank::where('course_id', $course->id)->withCount('questions')->get()->map(fn ($b) => ['id' => $b->id, 'title' => $b->title, 'question_count' => $b->questions_count])]);
    }

    public function storeBank(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $d = $request->validate(['title' => ['required', 'string', 'max:200']]);
        $b = QuestionBank::create(['course_id' => $course->id, 'title' => $d['title'], 'created_by' => $request->user()->id]);

        return response()->json(['data' => ['id' => $b->id, 'title' => $b->title, 'question_count' => 0]], 201);
    }

    private function questionRow(Question $q): array
    {
        return $q->only(['id', 'bank_id', 'type', 'category', 'difficulty', 'prompt', 'points', 'explanation', 'accepted_answers', 'case_sensitive', 'partial_credit', 'version'])
            + ['options' => $q->options->map(fn ($o) => $o->only(['id', 'label', 'is_correct', 'position']))->values(), 'archived' => (bool) $q->archived_at];
    }

    public function questions(Request $request, QuestionBank $bank): JsonResponse
    {
        Gate::authorize('manage', $bank->course ?? Course::findOrFail($bank->course_id));
        $f = $request->validate(['category' => ['nullable', 'string'], 'difficulty' => ['nullable', 'in:easy,medium,hard'], 'type' => ['nullable', 'string']]);
        $qs = $bank->questions()->with('options')->whereNull('archived_at')->when($f['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($f['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))->orderBy('created_at')->get();

        return response()->json(['data' => $qs->map(fn ($q) => $this->questionRow($q))]);
    }

    private function questionRules(): array
    {
        return [
            'type' => ['required', Rule::in(['mcq', 'multi', 'tf', 'short', 'essay'])], 'prompt' => ['required', 'string', 'max:20000'], 'category' => ['nullable', 'string', 'max:80'],
            'difficulty' => ['sometimes', Rule::in(['easy', 'medium', 'hard'])], 'points' => ['sometimes', 'numeric', 'gt:0', 'max:1000'], 'explanation' => ['nullable', 'string', 'max:5000'],
            'partial_credit' => ['sometimes', 'boolean'], 'case_sensitive' => ['sometimes', 'boolean'],
            'accepted_answers' => ['nullable', 'array', 'max:20'], 'accepted_answers.*' => ['string', 'max:500'],
            'options' => ['nullable', 'array', 'max:10'], 'options.*.label' => ['required_with:options', 'string', 'max:1000'], 'options.*.is_correct' => ['sometimes', 'boolean'],
            'true_is_correct' => ['sometimes', 'boolean'],
        ];
    }

    /** Structure rules per type, so a published question can never be unanswerable or ambiguous. */
    private function validateStructure(array $d): array
    {
        $opts = $d['options'] ?? [];
        switch ($d['type']) {
            case 'tf':
                $t = (bool) ($d['true_is_correct'] ?? true);
                $opts = [['label' => 'True', 'is_correct' => $t], ['label' => 'False', 'is_correct' => ! $t]];
                break;
            case 'mcq':
                if (count($opts) < 2 || count(array_filter($opts, fn ($o) => ! empty($o['is_correct']))) !== 1) {
                    throw new DomainException('A single-choice question needs at least two options and exactly one correct option.', 'invalid_question');
                }
                break;
            case 'multi':
                if (count($opts) < 2 || count(array_filter($opts, fn ($o) => ! empty($o['is_correct']))) < 1) {
                    throw new DomainException('A multiple-select question needs at least two options and at least one correct option.', 'invalid_question');
                }
                break;
            case 'short':
                if (empty($d['accepted_answers'])) {
                    throw new DomainException('List at least one accepted answer.', 'invalid_question');
                }
                $opts = [];
                break;
            default:
                $opts = [];
        }

        return $opts;
    }

    public function storeQuestion(Request $request, QuestionBank $bank): JsonResponse
    {
        $course = Course::findOrFail($bank->course_id);
        Gate::authorize('edit', $course);
        $d = $request->validate($this->questionRules());
        $opts = $this->validateStructure($d);
        $q = DB::transaction(function () use ($d, $opts, $bank, $request) {
            $q = new Question(collect($d)->only(['type', 'category', 'difficulty', 'points', 'explanation', 'accepted_answers', 'case_sensitive', 'partial_credit'])->all());
            $q->bank_id = $bank->id;
            $q->prompt = $this->html->clean($d['prompt']);
            $q->created_by = $request->user()->id;
            $q->save();
            foreach (array_values($opts) as $i => $o) {
                $q->options()->create(['label' => $o['label'], 'is_correct' => (bool) ($o['is_correct'] ?? false), 'position' => $i + 1]);
            }

            return $q->load('options');
        });

        return response()->json(['data' => $this->questionRow($q->fresh('options'))], 201);
    }

    /** Editing bumps the question version; attempts already started keep their frozen snapshot. */
    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        $course = Course::findOrFail($question->bank->course_id);
        Gate::authorize('edit', $course);
        $d = $request->validate($this->questionRules());
        $opts = $this->validateStructure($d);
        DB::transaction(function () use ($question, $d, $opts) {
            $question->fill(collect($d)->only(['type', 'category', 'difficulty', 'points', 'explanation', 'accepted_answers', 'case_sensitive', 'partial_credit'])->all());
            $question->prompt = $this->html->clean($d['prompt']);
            $question->version++;
            $question->save();
            $question->options()->delete();
            foreach (array_values($opts) as $i => $o) {
                $question->options()->create(['label' => $o['label'], 'is_correct' => (bool) ($o['is_correct'] ?? false), 'position' => $i + 1]);
            }
        });

        return response()->json(['data' => $this->questionRow($question->fresh('options'))]);
    }

    public function archiveQuestion(Question $question): JsonResponse
    {
        Gate::authorize('edit', Course::findOrFail($question->bank->course_id));
        $question->update(['archived_at' => now()]);

        return response()->json(['message' => 'Question archived.']);
    }

    // ------------------------------------------------------------ assessments
    private function rules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return [
            'kind' => [$r, Rule::in(['quiz', 'exam', 'supplementary'])], 'title' => [$r, 'string', 'max:200'], 'instructions' => ['nullable', 'string', 'max:20000'],
            'parent_assessment_id' => ['nullable', 'uuid', 'exists:assessments,id'], 'time_limit_minutes' => ['nullable', 'integer', 'between:1,600'],
            'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date', 'after:opens_at'], 'max_attempts' => ['sometimes', 'integer', 'between:1,20'],
            'pass_mark_percent' => ['sometimes', 'numeric', 'between:0,100'], 'weight' => ['sometimes', 'numeric', 'between:0,100'],
            'is_required' => ['sometimes', 'boolean'], 'is_final_exam' => ['sometimes', 'boolean'], 'require_all_lessons' => ['sometimes', 'boolean'],
            'shuffle_questions' => ['sometimes', 'boolean'], 'shuffle_options' => ['sometimes', 'boolean'],
            'results_release' => ['sometimes', Rule::in(['immediate', 'manual'])], 'review_policy' => ['sometimes', Rule::in(['never', 'after_close', 'immediate'])],
            'pools' => ['nullable', 'array', 'max:10'], 'pools.*.bank_id' => ['required', 'uuid', 'exists:question_banks,id'], 'pools.*.count' => ['required', 'integer', 'between:1,200'],
            'pools.*.category' => ['nullable', 'string', 'max:80'], 'pools.*.difficulty' => ['nullable', 'in:easy,medium,hard'],
            'question_ids' => ['sometimes', 'array', 'max:300'], 'question_ids.*' => ['uuid', 'distinct', 'exists:questions,id'],
        ];
    }

    private function sameCourse(Course $course, array $d): void
    {
        foreach ($d['pools'] ?? [] as $p) {
            if (! QuestionBank::where('id', $p['bank_id'])->where('course_id', $course->id)->exists()) {
                throw new DomainException('A question pool refers to another course\'s bank.', 'invalid_pool');
            }
        }
        if (! empty($d['question_ids']) && Question::whereIn('id', $d['question_ids'])->whereHas('bank', fn ($q) => $q->where('course_id', $course->id))->count() !== count($d['question_ids'])) {
            throw new DomainException('Some questions belong to another course.', 'invalid_question_ids');
        }
        if (! empty($d['parent_assessment_id']) && ! Assessment::where('id', $d['parent_assessment_id'])->where('course_id', $course->id)->exists()) {
            throw new DomainException('The original assessment belongs to another course.', 'invalid_parent');
        }
    }

    private function row(Assessment $a): array
    {
        return $a->only(['id', 'course_id', 'parent_assessment_id', 'kind', 'title', 'instructions', 'status', 'version', 'time_limit_minutes', 'max_attempts', 'pass_mark_percent', 'weight', 'is_required', 'is_final_exam', 'require_all_lessons', 'shuffle_questions', 'shuffle_options', 'results_release', 'review_policy', 'pools'])
            + ['opens_at' => $a->opens_at?->toIso8601String(), 'closes_at' => $a->closes_at?->toIso8601String(), 'published_at' => $a->published_at?->toIso8601String()]
            + ['question_ids' => $a->fixedQuestions->pluck('id')->values()];
    }

    public function index(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => Assessment::where('course_id', $course->id)->with('fixedQuestions')->latest()->get()->map(fn ($a) => $this->row($a)
            + ['attempt_count' => $a->attempts()->count(), 'awaiting_marking' => $a->attempts()->where('status', 'grading')->count()])]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $d = $request->validate($this->rules(true));
        $this->sameCourse($course, $d);
        $a = DB::transaction(function () use ($d, $course, $request) {
            $a = new Assessment(collect($d)->except(['question_ids', 'instructions'])->all());
            $a->course_id = $course->id;
            $a->instructions = $this->html->clean($d['instructions'] ?? null);
            $a->created_by = $request->user()->id;
            $a->save();
            $this->syncQuestions($a, $d['question_ids'] ?? []);

            return $a;
        });
        $this->audit->log('assessment.created', $a, new: ['title' => $a->title, 'kind' => $a->kind]);

        return response()->json(['data' => $this->row($a->fresh('fixedQuestions'))], 201);
    }

    public function show(Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage', $assessment->course);

        return response()->json(['data' => $this->row($assessment->load('fixedQuestions'))]);
    }

    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('edit', $assessment->course);
        $d = $request->validate($this->rules(false));
        $this->sameCourse($assessment->course, $d);
        DB::transaction(function () use ($assessment, $d) {
            $assessment->fill(collect($d)->except(['question_ids', 'instructions'])->all());
            if (array_key_exists('instructions', $d)) {
                $assessment->instructions = $this->html->clean($d['instructions']);
            }
            if ($assessment->status === 'published' && ($assessment->isDirty(['pools', 'pass_mark_percent', 'time_limit_minutes']) || isset($d['question_ids']))) {
                $assessment->version++;                  // content changed: new version; existing attempts keep their snapshot
            }
            $assessment->save();
            if (isset($d['question_ids'])) {
                $this->syncQuestions($assessment, $d['question_ids']);
            }
        });

        return response()->json(['data' => $this->row($assessment->fresh('fixedQuestions'))]);
    }

    private function syncQuestions(Assessment $a, array $ids): void
    {
        $a->fixedQuestions()->sync(collect($ids)->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i + 1]])->all());
    }

    public function publish(Assessment $assessment): JsonResponse
    {
        Gate::authorize('edit', $assessment->course);
        $assessment->load('fixedQuestions');
        $problems = [];
        if ($assessment->fixedQuestions->isEmpty() && empty($assessment->pools)) {
            $problems[] = 'Add questions or a question pool.';
        }
        foreach ($assessment->pools ?? [] as $p) {
            $avail = Question::where('bank_id', $p['bank_id'])->whereNull('archived_at')->when($p['category'] ?? null, fn ($q, $v) => $q->where('category', $v))->when($p['difficulty'] ?? null, fn ($q, $v) => $q->where('difficulty', $v))->count();
            if ($avail < $p['count']) {
                $problems[] = "A pool needs {$p['count']} questions but only $avail match.";
            }
        }
        if ($assessment->kind === 'supplementary' && ! $assessment->parent_assessment_id) {
            $problems[] = 'Choose the original assessment this supplements.';
        }
        if ($problems) {
            throw new DomainException('The assessment is not ready to publish.', 'assessment_not_ready', 422, $problems);
        }
        $assessment->forceFill(['status' => 'published', 'published_at' => $assessment->published_at ?? now()])->save();
        $this->audit->log('assessment.published', $assessment);

        return response()->json(['data' => $this->row($assessment)]);
    }

    public function grantAttempts(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage', $assessment->course);
        $d = $request->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'extra_attempts' => ['required', 'integer', 'between:1,5'], 'reason' => ['required', 'string', 'min:5', 'max:255']]);
        DB::table('assessment_attempt_grants')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'assessment_id' => $assessment->id, 'user_id' => $d['user_id'], 'extra_attempts' => $d['extra_attempts'], 'reason' => $d['reason'], 'granted_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->log('assessment.attempts_granted', $assessment, new: $d, reason: $d['reason']);

        return response()->json(['message' => 'Extra attempts granted.'], 201);
    }

    // ------------------------------------------------------------ attempts & marking
    public function attempts(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage', $assessment->course);
        $f = $request->validate(['status' => ['nullable', 'in:in_progress,grading,graded']]);
        $rows = $assessment->attempts()->with('user:id,name')->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->orderByDesc('started_at')->paginate(50);

        return response()->json(['data' => $rows->getCollection()->map(fn ($t) => ['id' => $t->id, 'student' => $t->user->only(['id', 'name']), 'attempt_no' => $t->attempt_no, 'status' => $t->status, 'total_score' => $t->total_score,
            'max_score' => $t->max_score, 'percent' => $t->percent, 'passed' => $t->passed, 'auto_submitted' => $t->auto_submitted, 'results_published' => (bool) $t->results_published_at, 'submitted_at' => $t->submitted_at?->toIso8601String()])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function attempt(AssessmentAttempt $attempt): JsonResponse
    {
        $attempt->load(['assessment.course', 'responses', 'user:id,name']);
        Gate::authorize('manage', $attempt->assessment->course);
        $resp = $attempt->responses->keyBy('question_id');

        return response()->json(['data' => ['id' => $attempt->id, 'student' => $attempt->user->only(['id', 'name']), 'status' => $attempt->status, 'total_score' => $attempt->total_score, 'max_score' => $attempt->max_score, 'percent' => $attempt->percent,
            'passed' => $attempt->passed, 'results_published' => (bool) $attempt->results_published_at, 'revision' => $attempt->revision, 'assessment_version' => $attempt->assessment_version,
            'questions' => collect($attempt->question_set)->map(fn ($q) => ['question_id' => $q['question_id'], 'type' => $q['type'], 'prompt' => $q['prompt'], 'points' => $q['points'], 'options' => $q['options'], 'accepted_answers' => $q['accepted_answers'],
                'answer' => $resp[$q['question_id']]->answer ?? null, 'is_correct' => $resp[$q['question_id']]->is_correct ?? null, 'auto_points' => $resp[$q['question_id']]->auto_points ?? null,
                'manual_points' => $resp[$q['question_id']]->manual_points ?? null, 'feedback' => $resp[$q['question_id']]->feedback ?? null])->values()]]);
    }

    public function markResponse(Request $request, AssessmentAttempt $attempt, string $questionId): JsonResponse
    {
        Gate::authorize('manage', $attempt->assessment->course);
        $d = $request->validate(['points' => ['required', 'numeric'], 'feedback' => ['nullable', 'string', 'max:5000'], 'reason' => ['nullable', 'string', 'max:500']]);
        $at = $this->svc->markManual($attempt, $questionId, (float) $d['points'], $d['feedback'] ?? null, $request->user(), $d['reason'] ?? null);

        return response()->json(['data' => $at->only(['status', 'total_score', 'max_score', 'percent', 'passed', 'revision'])]);
    }

    public function publishResults(Request $request, Assessment $assessment): JsonResponse
    {
        Gate::authorize('manage', $assessment->course);
        $n = 0;
        $assessment->attempts()->where('status', 'graded')->whereNull('results_published_at')->each(function ($at) use (&$n) {
            $this->svc->releaseResults($at, false);
            $n++;
        });
        $this->audit->log('assessment.results_published', $assessment, new: ['attempts' => $n]);

        return response()->json(['data' => ['published' => $n]]);
    }
}
