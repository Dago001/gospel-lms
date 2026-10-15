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

    public function lecturer(Request $request): JsonResponse
    {
        $u = $request->user();
        $courses = Course::query()->unless($u->hasPermission('courses.manage-all'), fn ($q) => $q->whereHas('instructors', fn ($w) => $w->where('users.id', $u->id)))->whereNotIn('status', ['archived'])->orderBy('title')->get(['id', 'title', 'status']);
        $ids = $courses->pluck('id');
        $enrol = CourseEnrollment::whereIn('course_id', $ids)->select('course_id', DB::raw("count(*) filter (where status = 'active') as active"), DB::raw("count(*) filter (where status = 'completed') as completed"))->groupBy('course_id')->get()->keyBy('course_id');
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
