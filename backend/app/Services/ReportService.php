<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Every figure is a query over stored facts. No placeholders, no estimates. */
class ReportService
{
    public function enrolments(string $from, string $to, ?string $courseId = null): array
    {
        $q = DB::table('course_enrollments')->whereBetween('enrolled_at', [$from, $to.' 23:59:59'])->when($courseId, fn ($q) => $q->where('course_id', $courseId));

        return [
            'daily' => (clone $q)->select(DB::raw("date(enrolled_at at time zone 'Africa/Lagos') as day"), 'source', DB::raw('count(*) as count'))->groupBy('day', 'source')->orderBy('day')->get(),
            'by_course' => (clone $q)->join('courses', 'courses.id', '=', 'course_enrollments.course_id')->select('courses.title', DB::raw('count(*) as count'))->groupBy('courses.title')->orderByDesc('count')->get(),
        ];
    }

    public function completion(?string $courseId = null): Collection
    {
        return DB::table('courses')->leftJoin('course_enrollments as e', fn ($j) => $j->on('e.course_id', '=', 'courses.id')->whereIn('e.status', ['active', 'completed']))
            ->where('courses.status', '!=', 'draft')->whereNull('courses.deleted_at')->when($courseId, fn ($q) => $q->where('courses.id', $courseId))
            ->select('courses.id', 'courses.title', DB::raw('count(e.id) as enrolled'), DB::raw("count(e.id) filter (where e.status = 'completed') as completed"))
            ->groupBy('courses.id', 'courses.title')->orderBy('courses.title')->get()
            ->map(fn ($r) => (array) $r + ['completion_rate' => $r->enrolled ? round($r->completed / $r->enrolled * 100, 1) : null]);
    }

    public function assessments(?string $courseId = null): Collection
    {
        return DB::table('assessments as a')->join('courses', 'courses.id', '=', 'a.course_id')->leftJoin('assessment_attempts as t', fn ($j) => $j->on('t.assessment_id', '=', 'a.id')->whereNotNull('t.results_published_at'))
            ->where('a.status', 'published')->when($courseId, fn ($q) => $q->where('a.course_id', $courseId))
            ->select('a.id', 'a.title', 'a.kind', 'courses.title as course', DB::raw('count(t.id) as attempts'), DB::raw('count(t.id) filter (where t.passed) as passed'), DB::raw('round(avg(t.percent)::numeric, 1) as average_percent'))
            ->groupBy('a.id', 'a.title', 'a.kind', 'courses.title')->orderBy('courses.title')->orderBy('a.title')->get()
            ->map(fn ($r) => (array) $r + ['pass_rate' => $r->attempts ? round($r->passed / $r->attempts * 100, 1) : null]);
    }

    public function gradingQueue(?string $courseId = null): Collection
    {
        $subs = DB::table('assignment_submissions as s')->join('assignments as a', 'a.id', '=', 's.assignment_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('submission_grades as g')->whereColumn('g.submission_id', 's.id')->where('g.status', 'published'))
            ->when($courseId, fn ($q) => $q->where('a.course_id', $courseId))->select('a.course_id', DB::raw('count(*) as submissions'))->groupBy('a.course_id')->pluck('submissions', 'course_id');
        $atts = DB::table('assessment_attempts as t')->join('assessments as a', 'a.id', '=', 't.assessment_id')->where('t.status', 'grading')
            ->when($courseId, fn ($q) => $q->where('a.course_id', $courseId))->select('a.course_id', DB::raw('count(*) as attempts'))->groupBy('a.course_id')->pluck('attempts', 'course_id');

        return Course::whereIn('id', $subs->keys()->merge($atts->keys())->unique())->orderBy('title')->get(['id', 'title'])
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'assignments_awaiting_grade' => (int) ($subs[$c->id] ?? 0), 'assessments_awaiting_marking' => (int) ($atts[$c->id] ?? 0)]);
    }

    public function attendance(?string $courseId = null): Collection
    {
        return DB::table('live_sessions as s')->join('courses', 'courses.id', '=', 's.course_id')->where('s.status', '!=', 'cancelled')->where('s.counts_for_attendance', true)
            ->whereRaw("s.starts_at + (s.duration_minutes || ' minutes')::interval < now()")->when($courseId, fn ($q) => $q->where('s.course_id', $courseId))
            ->select('s.id', 's.title', 'courses.title as course', 's.starts_at',
                DB::raw("(select count(*) from course_enrollments e where e.course_id = s.course_id and e.status in ('active','completed')) as enrolled"),
                DB::raw('(select count(*) from live_session_attendance a where a.live_session_id = s.id and a.present) as present'))
            ->orderByDesc('s.starts_at')->get()->map(fn ($r) => (array) $r + ['attendance_rate' => $r->enrolled ? round($r->present / $r->enrolled * 100, 1) : null]);
    }

    public function certificates(string $from, string $to): Collection
    {
        return DB::table('certificates')->whereBetween('issued_at', [$from, $to.' 23:59:59'])
            ->select(DB::raw("to_char(issued_at at time zone 'Africa/Lagos', 'YYYY-MM') as month"), DB::raw("count(*) filter (where status = 'active') as active"), DB::raw("count(*) filter (where status = 'revoked') as revoked"))
            ->groupBy('month')->orderBy('month')->get();
    }

    /** Platform-wide counters for the administrator dashboard. */
    public function overview(): array
    {
        $roles = DB::table('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->join('users', 'users.id', '=', 'role_user.user_id')->whereNull('users.deleted_at')
            ->select('roles.slug', DB::raw('count(*) as n'))->groupBy('roles.slug')->pluck('n', 'slug');
        $attempts = DB::table('assessment_attempts')->whereNotNull('results_published_at');

        return [
            'users' => ['total' => DB::table('users')->whereNull('deleted_at')->count(), 'by_role' => $roles, 'suspended' => DB::table('users')->where('status', 'suspended')->count()],
            'courses' => DB::table('courses')->whereNull('deleted_at')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'enrolments' => ['active' => DB::table('course_enrollments')->where('status', 'active')->count(), 'completed' => DB::table('course_enrollments')->where('status', 'completed')->count(),
                'last_30_days' => DB::table('course_enrollments')->where('enrolled_at', '>=', now()->subDays(30))->count()],
            'active_students' => ['last_7_days' => $this->activeStudents(7), 'last_30_days' => $this->activeStudents(30)],
            'assessments' => ['results_released' => (clone $attempts)->count(), 'passed' => (clone $attempts)->where('passed', true)->count(),
                'pass_rate' => (clone $attempts)->count() ? round((clone $attempts)->where('passed', true)->count() / (clone $attempts)->count() * 100, 1) : null],
            'pending_grading' => ['assignments' => $this->gradingQueue()->sum('assignments_awaiting_grade'), 'assessments' => $this->gradingQueue()->sum('assessments_awaiting_marking')],
            'certificates' => ['active' => DB::table('certificates')->where('status', 'active')->count(), 'revoked' => DB::table('certificates')->where('status', 'revoked')->count()],
            'support' => ['open' => DB::table('support_tickets')->whereIn('status', ['open', 'pending'])->count()],
        ];
    }

    public function activeStudents(int $days): int
    {
        return DB::table('users')->whereNull('deleted_at')->where('last_login_at', '>=', now()->subDays($days))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('role_user')->join('roles', 'roles.id', '=', 'role_user.role_id')->whereColumn('role_user.user_id', 'users.id')->where('roles.slug', 'student'))->count();
    }

    public function finance(): array
    {
        $since = now()->subDays(30);

        return [
            'revenue_30_days' => DB::table('payments')->where('status', 'success')->where('paid_at', '>=', $since)->select('currency', DB::raw('count(*) as count'), DB::raw('coalesce(sum(amount_minor),0) as total_minor'))->groupBy('currency')->get(),
            'failed_30_days' => DB::table('payments')->where('status', 'failed')->where('created_at', '>=', $since)->count(),
            'refunds_30_days' => DB::table('refunds')->where('status', 'processed')->where('processed_at', '>=', $since)->select('currency', DB::raw('count(*) as count'), DB::raw('coalesce(sum(amount_minor),0) as total_minor'))->groupBy('currency')->get(),
            'open_exceptions' => count(app(\App\Services\Payments\ReconciliationService::class)->exceptions()),
        ];
    }

    /** CSV download with spreadsheet-formula neutralisation. */
    public static function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, array_values((array) $row)));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
