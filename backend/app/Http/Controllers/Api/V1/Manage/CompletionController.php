<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseCompletionCheck;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CompletionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CompletionController extends Controller
{
    public function policy(Course $course, CompletionEngine $engine): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => ['policy' => $engine->policy($course), 'problems' => $engine->weightProblems($course)]]);
    }

    public function updatePolicy(Request $request, Course $course, CompletionEngine $engine, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('edit', $course);
        $d = $request->validate(['course_pass_percent' => ['sometimes', 'numeric', 'between:0,100'], 'attempt_selection' => ['sometimes', Rule::in(['best', 'latest'])], 'require_lessons' => ['sometimes', 'boolean'],
            'min_attendance_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'], 'lecturer_approval_required' => ['sometimes', 'boolean'], 'show_grade_on_certificate' => ['sometimes', 'boolean']]);
        $old = $course->completion_policy;
        $course->forceFill(['completion_policy' => array_merge($course->completion_policy ?? [], $d)])->save();
        $audit->log('course.completion_policy_changed', $course, ['policy' => $old], ['policy' => $course->completion_policy]);

        return response()->json(['data' => ['policy' => $engine->policy($course), 'problems' => $engine->weightProblems($course)]]);
    }

    /** One row per enrolled learner from the stored decision (no recomputation on read). */
    public function report(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);
        $f = $request->validate(['eligible' => ['nullable', 'in:0,1'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $p = CourseEnrollment::where('course_id', $course->id)->with('user:id,name')->orderBy('enrolled_at')->paginate($f['per_page'] ?? 50);
        $checks = CourseCompletionCheck::where('course_id', $course->id)->whereIn('user_id', $p->pluck('user_id'))->get()->keyBy('user_id');

        $rows = $p->getCollection()->map(function ($e) use ($checks) {
            $c = $checks[$e->user_id] ?? null;

            return ['user' => $e->user->only(['id', 'name']), 'enrolment_status' => $e->status, 'eligible' => $c?->eligible, 'grade_percent' => $c?->grade_percent,
                'reason_codes' => $c ? array_column($c->reasons, 'code') : null, 'evaluated_at' => $c?->evaluated_at->toIso8601String(), 'approved' => (bool) $c?->approved_by];
        })->when(isset($f['eligible']), fn ($c) => $c->where('eligible', (bool) $f['eligible']))->values();

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]]);
    }

    public function student(Course $course, User $user, CompletionEngine $engine): JsonResponse
    {
        Gate::authorize('manage', $course);
        abort_unless(CourseEnrollment::where(['course_id' => $course->id, 'user_id' => $user->id])->exists(), 404);
        $check = $engine->run($user, $course, 'staff_request');

        return response()->json(['data' => ['eligible' => $check->eligible, 'grade_percent' => $check->grade_percent, 'reasons' => $check->reasons, 'components' => $check->components]]);
    }

    public function approve(Request $request, Course $course, User $user, CompletionEngine $engine, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manage', $course);
        if (! ($engine->policy($course)['lecturer_approval_required'])) {
            throw new DomainException('This course does not require lecturer approval.', 'approval_not_required', 409);
        }
        abort_unless(CourseEnrollment::where(['course_id' => $course->id, 'user_id' => $user->id])->exists(), 404);
        $check = CourseCompletionCheck::firstOrNew(['user_id' => $user->id, 'course_id' => $course->id], ['evaluated_at' => now()]);
        $check->forceFill(['approved_by' => $request->user()->id, 'approved_at' => now()])->save();
        $audit->log('completion.approved', $check, new: ['student' => $user->id], actorId: $request->user()->id);
        $result = $engine->run($user, $course, 'lecturer_approval');

        return response()->json(['data' => ['eligible' => $result->eligible, 'reasons' => $result->reasons]]);
    }
}
