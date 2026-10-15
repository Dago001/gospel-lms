<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Jobs\SyncZoomAttendance;
use App\Models\Course;
use App\Models\LiveSession;
use App\Models\LiveSessionRecording;
use App\Models\User;
use App\Services\Live\LiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LiveSessionController extends Controller
{
    public function __construct(private LiveSessionService $svc) {}

    private function row(LiveSession $s): array
    {
        return ['id' => $s->id, 'course_id' => $s->course_id, 'lesson_id' => $s->lesson_id, 'title' => $s->title, 'description' => $s->description, 'starts_at' => $s->starts_at->toIso8601String(), 'duration_minutes' => $s->duration_minutes,
            'provider' => $s->provider, 'status' => $s->status, 'counts_for_attendance' => $s->counts_for_attendance, 'min_minutes_for_attendance' => $s->requiredMinutes(), 'attendance_synced_at' => $s->attendance_synced_at?->toIso8601String()];
    }

    public function index(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => LiveSession::where('course_id', $course->id)->orderBy('starts_at')->get()->map(fn ($s) => $this->row($s))]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', 'between:5,480'], 'provider' => ['required', Rule::in(['zoom', 'manual'])],
            'join_url' => ['required_if:provider,manual', 'nullable', 'url:https', 'max:500'], 'lesson_id' => ['nullable', 'uuid', 'exists:lessons,id'],
            'counts_for_attendance' => ['sometimes', 'boolean'], 'min_minutes_for_attendance' => ['sometimes', 'integer', 'between:0,480'],
        ]);
        if (! empty($d['lesson_id']) && ! $course->lessons()->whereKey($d['lesson_id'])->exists()) {
            throw new DomainException('That lesson is not part of this course.', 'invalid_lesson');
        }
        $s = $this->svc->create($course, $request->user(), $d);

        return response()->json(['data' => $this->row($s)], 201);
    }

    public function update(Request $request, LiveSession $session): JsonResponse
    {
        Gate::authorize('edit', $session->course);
        $d = $request->validate(['title' => ['sometimes', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'starts_at' => ['sometimes', 'date', 'after:now'], 'duration_minutes' => ['sometimes', 'integer', 'between:5,480'],
            'counts_for_attendance' => ['sometimes', 'boolean'], 'min_minutes_for_attendance' => ['sometimes', 'integer', 'between:0,480']]);

        return response()->json(['data' => $this->row($this->svc->reschedule($session, $d))]);
    }

    public function destroy(LiveSession $session): JsonResponse
    {
        Gate::authorize('edit', $session->course);
        if ($session->status === 'ended') {
            throw new DomainException('A session that has been held cannot be cancelled.', 'session_closed', 409);
        }
        $this->svc->cancel($session);

        return response()->json(['message' => 'Session cancelled.']);
    }

    public function attendance(LiveSession $session): JsonResponse
    {
        Gate::authorize('manage', $session->course);
        $enrolled = $session->course->enrollments()->whereIn('status', ['active', 'completed'])->with('user:id,name,email')->get();
        $rows = $session->attendance()->get()->keyBy('user_id');

        return response()->json(['data' => $enrolled->map(fn ($e) => ['user' => $e->user->only(['id', 'name']), 'present' => (bool) ($rows[$e->user_id]->present ?? false), 'minutes' => $rows[$e->user_id]->minutes_attended ?? 0,
            'source' => $rows[$e->user_id]->source ?? null, 'note' => $rows[$e->user_id]->note ?? null])->values(), 'required_minutes' => $session->requiredMinutes()]);
    }

    public function setAttendance(Request $request, LiveSession $session, User $user): JsonResponse
    {
        Gate::authorize('manage', $session->course);
        $d = $request->validate(['present' => ['required', 'boolean'], 'minutes' => ['nullable', 'integer', 'between:0,480'], 'note' => ['required', 'string', 'min:3', 'max:255']]);
        $row = $this->svc->setAttendance($session, $user, (bool) $d['present'], $d['minutes'] ?? null, $d['note'], $request->user());

        return response()->json(['data' => ['present' => $row->present, 'minutes' => $row->minutes_attended, 'source' => $row->source]]);
    }

    public function syncNow(LiveSession $session): JsonResponse
    {
        Gate::authorize('manage', $session->course);
        if ($session->provider !== 'zoom') {
            throw new DomainException('Only Zoom sessions have an automatic report.', 'not_zoom', 422);
        }
        SyncZoomAttendance::dispatch($session->id);

        return response()->json(['message' => 'Attendance sync queued.'], 202);
    }

    public function recordings(LiveSession $session): JsonResponse
    {
        Gate::authorize('manage', $session->course);

        return response()->json(['data' => $session->recordings()->get()->map(fn ($r) => ['id' => $r->id, 'duration_seconds' => $r->duration_seconds, 'published' => $r->published])]);
    }

    public function publishRecording(Request $request, LiveSessionRecording $recording): JsonResponse
    {
        Gate::authorize('manage', $recording->session()->first()->course);
        $recording->update(['published' => $request->validate(['published' => ['required', 'boolean']])['published']]);

        return response()->json(['data' => ['id' => $recording->id, 'published' => $recording->published]]);
    }
}
