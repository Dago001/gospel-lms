<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\AuditLogger;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $r) {}

    private function range(Request $request): array
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'course_id' => ['nullable', 'uuid'], 'format' => ['nullable', 'in:json,csv']]);

        return [$d['from'] ?? now()->subDays(30)->toDateString(), $d['to'] ?? now()->toDateString(), $d['course_id'] ?? null, ($d['format'] ?? 'json') === 'csv'];
    }

    private function out(Request $request, bool $csv, string $name, $rows, array $json = []): JsonResponse|StreamedResponse
    {
        if ($csv) {
            app(AuditLogger::class)->log('report.exported', null, new: ['report' => $name] + $request->only(['from', 'to', 'course_id']));
            $rows = collect($rows);

            return ReportService::csv("$name-".now()->format('Ymd').'.csv', array_keys((array) ($rows->first() ?? ['empty' => ''])), $rows);
        }

        return response()->json(['data' => $json ?: $rows]);
    }

    public function enrolments(Request $request)
    {
        [$from, $to, $course, $csv] = $this->range($request);
        $d = $this->r->enrolments($from, $to, $course);

        return $this->out($request, $csv, 'enrolments', $d['daily'], $d);
    }

    public function completion(Request $request)
    {
        [, , $course, $csv] = $this->range($request);

        return $this->out($request, $csv, 'completion', $this->r->completion($course)->values());
    }

    public function assessments(Request $request)
    {
        [, , $course, $csv] = $this->range($request);

        return $this->out($request, $csv, 'assessment-pass-rates', $this->r->assessments($course)->values());
    }

    public function gradingQueue(Request $request)
    {
        [, , $course, $csv] = $this->range($request);

        return $this->out($request, $csv, 'grading-queue', $this->r->gradingQueue($course)->values());
    }

    public function attendance(Request $request)
    {
        [, , $course, $csv] = $this->range($request);

        return $this->out($request, $csv, 'attendance', $this->r->attendance($course)->values());
    }

    public function certificates(Request $request)
    {
        [$from, $to, , $csv] = $this->range($request);

        return $this->out($request, $csv, 'certificates', $this->r->certificates($from, $to)->values());
    }

    /** A lecturer sees analytics for their own course only. */
    public function course(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);
        [$from, $to] = $this->range($request);

        return response()->json(['data' => ['enrolments' => $this->r->enrolments($from, $to, $course->id), 'completion' => $this->r->completion($course->id)->first(), 'assessments' => $this->r->assessments($course->id)->values(),
            'grading_queue' => $this->r->gradingQueue($course->id)->first(), 'attendance' => $this->r->attendance($course->id)->values()]]);
    }
}
