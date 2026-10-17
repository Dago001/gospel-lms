<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LiveSession;
use App\Services\ReportService;
use App\Services\Video\ProgressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function student(Request $request, ProgressionService $progression): JsonResponse
    {
        $u = $request->user();
        $enrols = CourseEnrollment::where('user_id', $u->id)->whereIn('status', ['active', 'completed'])->with('course:id,title,slug')->get();
        $courseIds = $enrols->pluck('course_id');

        $courses = $enrols->map(function ($e) use ($u, $progression) {
            $s = $progression->summary($u, Course::find($e->course_id));

            return ['course_id' => $e->course_id, 'title' => $e->course->title, 'slug' => $e->course->slug, 'status' => $e->status, 'progress_percent' => $s['percent'], 'required_completed' => $s['required_completed'], 'required_total' => $s['required_total'], 'next_lesson_id' => $s['next_lesson_id']];
        })->values();

        $deadlines = Assignment::whereIn('course_id', $courseIds)->where('status', 'published')->where('due_at', '>', now())->whereDoesntHave('submissions', fn ($q) => $q->where('user_id', $u->id))
            ->orderBy('due_at')->limit(10)->get()->map(fn ($a) => ['type' => 'assignment', 'id' => $a->id, 'course_id' => $a->course_id, 'title' => $a->title, 'due_at' => $a->due_at->toIso8601String()])
            ->concat(Assessment::whereIn('course_id', $courseIds)->where('status', 'published')->where('closes_at', '>', now())->whereDoesntHave('attempts', fn ($q) => $q->where('user_id', $u->id))
                ->orderBy('closes_at')->limit(10)->get()->map(fn ($a) => ['type' => 'assessment', 'id' => $a->id, 'course_id' => $a->course_id, 'title' => $a->title, 'due_at' => $a->closes_at->toIso8601String()]))
            ->sortBy('due_at')->values()->take(10);

        return response()->json(['data' => [
            'courses' => $courses,
            'upcoming_live_sessions' => LiveSession::whereIn('course_id', $courseIds)->where('status', 'scheduled')->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(14))->orderBy('starts_at')->limit(5)->get()
                ->map(fn ($s) => ['id' => $s->id, 'course_id' => $s->course_id, 'title' => $s->title, 'starts_at' => $s->starts_at->toIso8601String()]),
            'upcoming_deadlines' => $deadlines,
            'results_awaiting' => DB::table('assessment_attempts')->where('user_id', $u->id)->where(fn ($q) => $q->where('status', 'grading')->orWhere(fn ($w) => $w->where('status', 'graded')->whereNull('results_published_at')))->count(),
            'certificates' => Certificate::where(['user_id' => $u->id, 'status' => 'active'])->count(),
            'unread_notifications' => $u->unreadNotifications()->count(),
        ]]);
    }

    /** Everything the student has been graded on, released results only. */
    public function results(Request $request): JsonResponse
    {
        $uid = $request->user()->id;
        $assignments = DB::table('submission_grades as g')->join('assignment_submissions as s', 's.id', '=', 'g.submission_id')->join('assignments as a', 'a.id', '=', 's.assignment_id')->join('courses as c', 'c.id', '=', 'a.course_id')
            ->where('s.user_id', $uid)->where('g.status', 'published')->orderByDesc('g.published_at')
            ->get(['c.id as course_id', 'c.title as course', 'a.title', 'a.max_score', 'a.pass_mark', 'g.final_score', 'g.penalty_percent', 'g.feedback', 'g.published_at', 's.attempt'])
            ->map(fn ($r) => ['kind' => 'assignment', 'course_id' => $r->course_id, 'course' => $r->course, 'title' => $r->title, 'score' => (float) $r->final_score, 'max' => (float) $r->max_score,
                'percent' => round($r->final_score / $r->max_score * 100, 1), 'passed' => $r->pass_mark === null ? null : $r->final_score >= $r->pass_mark, 'feedback' => $r->feedback, 'at' => $r->published_at, 'attempt' => $r->attempt, 'penalty_percent' => (float) $r->penalty_percent]);
        $assessments = DB::table('assessment_attempts as t')->join('assessments as a', 'a.id', '=', 't.assessment_id')->join('courses as c', 'c.id', '=', 'a.course_id')
            ->where('t.user_id', $uid)->whereNotNull('t.results_published_at')->orderByDesc('t.results_published_at')
            ->get(['c.id as course_id', 'c.title as course', 'a.title', 'a.kind', 't.id as attempt_id', 't.attempt_no', 't.total_score', 't.max_score', 't.percent', 't.passed', 't.results_published_at'])
            ->map(fn ($r) => ['kind' => 'assessment', 'course_id' => $r->course_id, 'course' => $r->course, 'title' => $r->title, 'assessment_kind' => $r->kind, 'attempt_id' => $r->attempt_id, 'attempt' => $r->attempt_no,
                'score' => (float) $r->total_score, 'max' => (float) $r->max_score, 'percent' => (float) $r->percent, 'passed' => (bool) $r->passed, 'at' => $r->results_published_at]);

        return response()->json(['data' => $assignments->concat($assessments)->sortByDesc('at')->values()]);
    }

    public function lecturer(Request $request): JsonResponse
    {
        $u = $request->user();
        $courses = Course::query()->unless($u->hasPermission('courses.manage-all'), fn ($q) => $q->whereHas('instructors', fn ($w) => $w->where('users.id', $u->id)))->whereNotIn('status', ['archived'])->orderBy('title')->get(['id', 'title', 'status']);
        $ids = $courses->pluck('id');
        $enrol = CourseEnrollment::whereIn('course_id', $ids)->select('course_id', DB::raw(\App\Support\DbCompat::countWhere("status = 'active'") . ' as active'), DB::raw(\App\Support\DbCompat::countWhere("status = 'completed'") . ' as completed'))->groupBy('course_id')->get()->keyBy('course_id');
        $queue = app(ReportService::class)->gradingQueue()->whereIn('id', $ids)->keyBy('id');

        return response()->json(['data' => [
            'courses' => $courses->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'status' => $c->status, 'active_students' => (int) ($enrol[$c->id]->active ?? 0), 'completed_students' => (int) ($enrol[$c->id]->completed ?? 0),
                'assignments_awaiting_grade' => $queue[$c->id]['assignments_awaiting_grade'] ?? 0, 'assessments_awaiting_marking' => $queue[$c->id]['assessments_awaiting_marking'] ?? 0])->values(),
            'upcoming_live_sessions' => LiveSession::whereIn('course_id', $ids)->where('status', 'scheduled')->where('starts_at', '>', now())->orderBy('starts_at')->limit(5)->get()->map(fn ($s) => ['id' => $s->id, 'course_id' => $s->course_id, 'title' => $s->title, 'starts_at' => $s->starts_at->toIso8601String()]),
            'totals' => ['awaiting_grading' => $queue->sum('assignments_awaiting_grade') + $queue->sum('assessments_awaiting_marking')],
        ]]);
    }

    /** Sections appear only if the viewer holds the matching report permission. */
    public function admin(Request $request, ReportService $reports): JsonResponse
    {
        $u = $request->user();
        $data = [];
        if ($u->hasPermission('reports.academic')) {
            $data['academic'] = $reports->overview();
        }
        if ($u->hasPermission('reports.finance')) {
            $data['finance'] = $reports->finance();
        }
        abort_if(! $data, 403);

        return response()->json(['data' => $data]);
    }
}
