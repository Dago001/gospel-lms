<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\SubmissionFile;
use App\Services\AssignmentService;
use App\Services\EnrolmentService;
use App\Services\PrivateFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AssignmentLearnController extends Controller
{
    private function view(Assignment $a): array
    {
        return $a->only(['id', 'course_id', 'lesson_id', 'title', 'instructions', 'max_score', 'pass_mark', 'late_policy', 'late_penalty_percent', 'allow_resubmission', 'max_attempts', 'max_files', 'max_file_mb', 'allowed_extensions'])
            + ['opens_at' => $a->opens_at?->toIso8601String(), 'due_at' => $a->due_at?->toIso8601String(), 'late_cutoff_at' => $a->late_cutoff_at?->toIso8601String()];
    }

    private function mine(Assignment $a, $user): array
    {
        return $a->submissions()->where('user_id', $user->id)->with(['files', 'grade.submission'])->orderBy('attempt')->get()->map(function ($s) {
            $g = $s->grade && $s->grade->status === 'published' ? $s->grade : null;   // drafts are invisible to students

            return ['id' => $s->id, 'attempt' => $s->attempt, 'status' => $s->status === 'graded' && ! $g ? 'submitted' : $s->status, 'is_late' => $s->is_late, 'submitted_at' => $s->submitted_at->toIso8601String(),
                'text_response' => $s->text_response, 'files' => $s->files->map(fn ($f) => ['id' => $f->id, 'name' => $f->original_name, 'size_bytes' => $f->size_bytes]),
                'grade' => $g ? ['raw_score' => $g->raw_score, 'penalty_percent' => $g->penalty_percent, 'final_score' => $g->final_score, 'feedback' => $g->feedback, 'criterion_scores' => $g->criterion_scores, 'published_at' => $g->published_at->toIso8601String()] : null];
        })->all();
    }

    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);

        return response()->json(['data' => Assignment::where('course_id', $course->id)->where('status', 'published')->orderBy('due_at')->get()
            ->map(fn ($a) => $this->view($a) + ['my_submissions' => $this->mine($a, $request->user())])]);
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        abort_unless($assignment->status === 'published', 404);
        Gate::authorize('learn', $assignment->course);

        return response()->json(['data' => $this->view($assignment) + ['my_submissions' => $this->mine($assignment, $request->user())]]);
    }

    public function submit(Request $request, Assignment $assignment, AssignmentService $svc): JsonResponse
    {
        abort_unless($assignment->status === 'published', 404);
        $d = $request->validate(['text_response' => ['nullable', 'string', 'max:50000'], 'files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file']]);
        if (! app(EnrolmentService::class)->userHasAccess($request->user(), $assignment->course)) {
            abort(403, 'You are not enrolled in this course.');
        }
        $s = $svc->submit($request->user(), $assignment, $d['text_response'] ?? null, $request->file('files', []));

        return response()->json(['data' => ['id' => $s->id, 'attempt' => $s->attempt, 'status' => $s->status, 'is_late' => $s->is_late, 'submitted_at' => $s->submitted_at->toIso8601String()]], 201);
    }

    /** Learners can only download their own submitted files. */
    public function downloadFile(Request $request, SubmissionFile $file)
    {
        abort_unless($file->submission->user_id === $request->user()->id, 404);

        return PrivateFile::send($file->path, $file->original_name);
    }
}
