<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\GradingRubric;
use App\Models\SubmissionFile;
use App\Services\AuditLogger;
use App\Services\GradingService;
use App\Services\HtmlSanitizer;
use App\Services\PrivateFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    public const DEFAULT_EXTENSIONS = ['pdf', 'doc', 'docx', 'txt', 'odt', 'jpg', 'jpeg', 'png', 'zip'];

    public function __construct(private HtmlSanitizer $html, private AuditLogger $audit) {}

    private function rules(bool $create): array
    {
        $r = $create ? 'required' : 'sometimes';

        return [
            'title' => [$r, 'string', 'max:200'], 'instructions' => ['nullable', 'string', 'max:50000'],
            'lesson_id' => ['nullable', 'uuid', 'exists:lessons,id'], 'rubric_id' => ['nullable', 'uuid', 'exists:grading_rubrics,id'],
            'max_score' => [$r, 'numeric', 'gt:0', 'max:100000'], 'pass_mark' => ['nullable', 'numeric', 'min:0', 'lte:max_score'],
            'weight' => ['sometimes', 'numeric', 'between:0,100'], 'is_required' => ['sometimes', 'boolean'],
            'opens_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date', 'after:opens_at'],
            'late_policy' => ['sometimes', Rule::in(['reject', 'penalty'])], 'late_penalty_percent' => ['sometimes', 'numeric', 'between:0,100'], 'late_cutoff_at' => ['nullable', 'date', 'after:due_at'],
            'allow_resubmission' => ['sometimes', 'boolean'], 'max_attempts' => ['sometimes', 'integer', 'between:1,10'],
            'max_files' => ['sometimes', 'integer', 'between:0,10'], 'max_file_mb' => ['sometimes', 'integer', 'between:1,100'],
            'allowed_extensions' => ['sometimes', 'array', 'min:1'], 'allowed_extensions.*' => [Rule::in(self::DEFAULT_EXTENSIONS)],
        ];
    }

    private function row(Assignment $a): array
    {
        return $a->only(['id', 'course_id', 'lesson_id', 'rubric_id', 'title', 'instructions', 'max_score', 'pass_mark', 'weight', 'is_required', 'late_policy', 'late_penalty_percent', 'allow_resubmission', 'max_attempts', 'max_files', 'max_file_mb', 'allowed_extensions', 'status'])
            + ['opens_at' => $a->opens_at?->toIso8601String(), 'due_at' => $a->due_at?->toIso8601String(), 'late_cutoff_at' => $a->late_cutoff_at?->toIso8601String(), 'published_at' => $a->published_at?->toIso8601String()];
    }

    /** Rubric and lesson must belong to the same course; a rubric fixes the maximum score. */
    private function check(Course $course, array $d, ?Assignment $existing = null): void
    {
        if (! empty($d['lesson_id']) && ! $course->lessons()->whereKey($d['lesson_id'])->exists()) {
            throw new DomainException('That lesson is not part of this course.', 'invalid_lesson');
        }
        $rubricId = $d['rubric_id'] ?? $existing?->rubric_id;
        if ($rubricId) {
            $rubric = GradingRubric::where('course_id', $course->id)->with('criteria')->find($rubricId);
            if (! $rubric) {
                throw new DomainException('That rubric belongs to a different course.', 'invalid_rubric');
            }
            $max = (float) ($d['max_score'] ?? $existing?->max_score);
            if (abs($rubric->criteria->sum('max_points') - $max) > 0.001) {
                throw new DomainException('The maximum score must equal the total rubric points ('.$rubric->criteria->sum('max_points').').', 'rubric_total_mismatch');
            }
        }
    }

    public function index(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => $course->hasMany(Assignment::class)->latest()->get()->map(fn ($a) => $this->row($a) + ['submission_count' => $a->submissions()->count(), 'ungraded_count' => $a->submissions()->where('status', '!=', 'graded')->count()])]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $d = $request->validate($this->rules(true));
        $this->check($course, $d);
        $a = new Assignment(collect($d)->except('instructions')->all() + ['allowed_extensions' => $d['allowed_extensions'] ?? self::DEFAULT_EXTENSIONS]);
        $a->course_id = $course->id;
        $a->instructions = $this->html->clean($d['instructions'] ?? null);
        $a->created_by = $request->user()->id;
        $a->save();
        $this->audit->log('assignment.created', $a, new: ['title' => $a->title]);

        return response()->json(['data' => $this->row($a->fresh())], 201);
    }

    public function show(Assignment $assignment): JsonResponse
    {
        Gate::authorize('manage', $assignment->course);

        return response()->json(['data' => $this->row($assignment)]);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('edit', $assignment->course);
        $d = $request->validate($this->rules(false));
        $this->check($assignment->course, $d, $assignment);
        if ($assignment->status === 'published' && $assignment->submissions()->exists() && (isset($d['max_score']) || array_key_exists('rubric_id', $d))) {
            throw new DomainException('The score scale cannot change after students have submitted.', 'scale_locked', 409);
        }
        $assignment->fill(collect($d)->except('instructions')->all());
        if (array_key_exists('instructions', $d)) {
            $assignment->instructions = $this->html->clean($d['instructions']);
        }
        $assignment->save();

        return response()->json(['data' => $this->row($assignment)]);
    }

    public function publish(Assignment $assignment): JsonResponse
    {
        Gate::authorize('edit', $assignment->course);
        $assignment->forceFill(['status' => 'published', 'published_at' => $assignment->published_at ?? now()])->save();
        $this->audit->log('assignment.published', $assignment);

        return response()->json(['data' => $this->row($assignment)]);
    }

    // ---- rubrics ----
    public function rubrics(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => GradingRubric::where('course_id', $course->id)->with('criteria')->get()->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'total_points' => $r->criteria->sum('max_points'),
            'criteria' => $r->criteria->map(fn ($c) => $c->only(['id', 'title', 'description', 'max_points']))->values()])]);
    }

    public function storeRubric(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $d = $request->validate(['title' => ['required', 'string', 'max:200'], 'criteria' => ['required', 'array', 'min:1', 'max:20'],
            'criteria.*.title' => ['required', 'string', 'max:200'], 'criteria.*.description' => ['nullable', 'string', 'max:1000'], 'criteria.*.max_points' => ['required', 'numeric', 'gt:0', 'max:1000']]);
        $r = DB::transaction(function () use ($d, $course, $request) {
            $r = GradingRubric::create(['course_id' => $course->id, 'title' => $d['title'], 'created_by' => $request->user()->id]);
            foreach ($d['criteria'] as $i => $c) {
                $r->criteria()->create($c + ['position' => $i + 1]);
            }

            return $r->load('criteria');
        });

        return response()->json(['data' => ['id' => $r->id, 'title' => $r->title, 'total_points' => $r->criteria->sum('max_points'), 'criteria' => $r->criteria->map(fn ($c) => $c->only(['id', 'title', 'max_points']))]], 201);
    }

    // ---- submissions & grading ----
    public function submissions(Request $request, Assignment $assignment): JsonResponse
    {
        Gate::authorize('manage', $assignment->course);
        $f = $request->validate(['status' => ['nullable', Rule::in(['submitted', 'graded', 'returned', 'ungraded'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $p = $assignment->submissions()->with(['user:id,name,email', 'grade:id,submission_id,final_score,status'])
            ->when(($f['status'] ?? null) === 'ungraded', fn ($q) => $q->whereDoesntHave('grade', fn ($g) => $g->where('status', 'published')))
            ->when(in_array($f['status'] ?? null, ['submitted', 'graded', 'returned'], true), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('submitted_at')->paginate($f['per_page'] ?? 25);

        return response()->json(['data' => $p->getCollection()->map(fn ($s) => ['id' => $s->id, 'student' => ['id' => $s->user->id, 'name' => $s->user->name], 'attempt' => $s->attempt, 'status' => $s->status,
            'is_late' => $s->is_late, 'submitted_at' => $s->submitted_at->toIso8601String(), 'grade' => $s->grade ? ['final_score' => $s->grade->final_score, 'status' => $s->grade->status] : null])->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }

    public function submission(AssignmentSubmission $submission): JsonResponse
    {
        $submission->load(['user:id,name,email', 'files', 'grade.changes', 'assignment.rubric.criteria']);
        Gate::authorize('manage', $submission->assignment->course);

        return response()->json(['data' => [
            'id' => $submission->id, 'student' => $submission->user->only(['id', 'name', 'email']), 'attempt' => $submission->attempt, 'status' => $submission->status, 'text_response' => $submission->text_response,
            'is_late' => $submission->is_late, 'late_minutes' => $submission->late_minutes, 'submitted_at' => $submission->submitted_at->toIso8601String(),
            'files' => $submission->files->map(fn ($f) => ['id' => $f->id, 'name' => $f->original_name, 'size_bytes' => $f->size_bytes, 'mime' => $f->mime, 'sha256' => $f->sha256]),
            'rubric' => $submission->assignment->rubric?->criteria->map(fn ($c) => $c->only(['id', 'title', 'max_points'])),
            'grade' => $submission->grade ? $submission->grade->only(['raw_score', 'penalty_percent', 'final_score', 'feedback', 'criterion_scores', 'status', 'revision']) : null,
            'grade_history' => $submission->grade?->changes->map(fn ($c) => ['previous_score' => $c->previous_score, 'new_score' => $c->new_score, 'reason' => $c->reason, 'changed_by' => $c->changed_by, 'at' => $c->created_at->toIso8601String()]) ?? [],
        ]]);
    }

    public function grade(Request $request, AssignmentSubmission $submission, GradingService $svc): JsonResponse
    {
        Gate::authorize('manage', $submission->assignment->course);
        $d = $request->validate(['score' => ['nullable', 'numeric'], 'criterion_scores' => ['nullable', 'array'], 'feedback' => ['nullable', 'string', 'max:20000'], 'publish' => ['sometimes', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $g = $svc->grade($submission, $request->user(), $d);

        return response()->json(['data' => $g->only(['raw_score', 'penalty_percent', 'final_score', 'status', 'revision'])]);
    }

    /** Send back for revision: allows one more attempt even if resubmission is otherwise disabled. */
    public function returnForRevision(AssignmentSubmission $submission): JsonResponse
    {
        Gate::authorize('manage', $submission->assignment->course);
        if ($submission->grade?->status === 'published') {
            throw new DomainException('A published grade must be amended, not returned.', 'already_graded', 409);
        }
        if ($submission->attempt >= $submission->assignment->max_attempts) {
            throw new DomainException('No attempts remain; raise the attempt limit first.', 'attempt_limit_reached', 409);
        }
        $submission->update(['status' => 'returned']);
        $this->audit->log('submission.returned', $submission);

        return response()->json(['data' => ['status' => 'returned']]);
    }

    public function downloadFile(SubmissionFile $file)
    {
        Gate::authorize('manage', $file->submission->assignment->course);

        return PrivateFile::send($file->path, $file->original_name);
    }
}
