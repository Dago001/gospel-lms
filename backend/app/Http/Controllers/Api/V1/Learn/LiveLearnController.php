<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LiveSession;
use App\Services\EnrolmentService;
use App\Services\Live\LiveSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LiveLearnController extends Controller
{
    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);
        $mine = \App\Models\LiveSessionAttendance::where('user_id', $request->user()->id)->pluck('present', 'live_session_id');

        return response()->json(['data' => LiveSession::where('course_id', $course->id)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get()->map(fn ($s) => [
            'id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'starts_at' => $s->starts_at->toIso8601String(), 'duration_minutes' => $s->duration_minutes, 'status' => $s->status,
            'counts_for_attendance' => $s->counts_for_attendance, 'held' => $s->endsAt()->isPast(), 'my_attendance' => $s->endsAt()->isPast() ? (bool) ($mine[$s->id] ?? false) : null,
            'recordings' => $s->recordings()->where('published', true)->count()])]);
    }

    public function join(Request $request, LiveSession $session, LiveSessionService $svc): JsonResponse
    {
        return response()->json(['data' => ['join_url' => $svc->joinLink($request->user(), $session)]]);
    }

    /** Only enrolled learners, only recordings the lecturer has released. */
    public function recordings(Request $request, LiveSession $session): JsonResponse
    {
        abort_unless(app(EnrolmentService::class)->userHasAccess($request->user(), $session->course) || Gate::allows('manage', $session->course), 403);

        return response()->json(['data' => $session->recordings()->where('published', true)->get()->makeVisible('play_url')->map(fn ($r) => ['id' => $r->id, 'play_url' => $r->play_url, 'duration_seconds' => $r->duration_seconds])]);
    }
}
