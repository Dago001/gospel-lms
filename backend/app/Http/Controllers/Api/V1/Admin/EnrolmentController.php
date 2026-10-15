<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnrolmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['course_id' => ['nullable', 'uuid'], 'user_id' => ['nullable', 'uuid'], 'status' => ['nullable', 'string'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $page = CourseEnrollment::query()->with(['user:id,name,email', 'course:id,title,slug'])
            ->when($f['course_id'] ?? null, fn ($q, $v) => $q->where('course_id', $v))->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest('enrolled_at')->paginate($f['per_page'] ?? 25);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    /** Manual enrolment (e.g. scholarships, cash payment handled offline). Audited; skips prerequisite checks by design. */
    public function store(Request $request, EnrolmentService $svc): JsonResponse
    {
        $d = $request->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'course_id' => ['required', 'uuid', 'exists:courses,id'], 'reason' => ['required', 'string', 'max:255']]);
        $course = Course::findOrFail($d['course_id']);
        $e = $svc->enrol(User::findOrFail($d['user_id']), $course, 'admin', enforceRules: false);
        app(AuditLogger::class)->log('enrolment.admin_created', $e, new: ['course_id' => $course->id, 'user_id' => $d['user_id']], reason: $d['reason']);

        return response()->json(['data' => ['id' => $e->id, 'status' => $e->status]], $e->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, CourseEnrollment $enrollment, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(['active', 'suspended', 'cancelled'])], 'reason' => ['required', 'string', 'max:255']]);
        $old = $enrollment->status;
        $enrollment->update(['status' => $d['status']]);
        $audit->log('enrolment.status_changed', $enrollment, ['status' => $old], ['status' => $d['status']], $d['reason']);

        return response()->json(['data' => ['id' => $enrollment->id, 'status' => $enrollment->status]]);
    }
}
