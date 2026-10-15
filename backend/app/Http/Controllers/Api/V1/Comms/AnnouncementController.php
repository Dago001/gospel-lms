<?php

namespace App\Http\Controllers\Api\V1\Comms;

use App\Http\Controllers\Controller;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Models\Course;
use App\Services\AuditLogger;
use App\Services\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AnnouncementController extends Controller
{
    public function __construct(private HtmlSanitizer $html, private AuditLogger $audit) {}

    private function row(Announcement $a): array
    {
        return ['id' => $a->id, 'course_id' => $a->course_id, 'title' => $a->title, 'body' => $a->body, 'audience' => $a->audience, 'pinned' => $a->pinned,
            'publish_at' => $a->publish_at->toIso8601String(), 'expires_at' => $a->expires_at?->toIso8601String(), 'author' => $a->author?->name];
    }

    private function visible($q)
    {
        return $q->where('publish_at', '<=', now())->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('pinned')->orderByDesc('publish_at');
    }

    private function validated(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000'], 'pinned' => ['sometimes', 'boolean'], 'notify' => ['sometimes', 'boolean'],
            'publish_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after:publish_at'], 'audience' => ['sometimes', 'in:all,students,lecturers']]);
    }

    private function make(Request $request, ?Course $course, array $d): Announcement
    {
        $a = new Announcement(['title' => $d['title'], 'pinned' => $d['pinned'] ?? false, 'notify' => $d['notify'] ?? false, 'audience' => $course ? 'all' : ($d['audience'] ?? 'all'),
            'publish_at' => $d['publish_at'] ?? now(), 'expires_at' => $d['expires_at'] ?? null]);
        $a->course_id = $course?->id;
        $a->author_id = $request->user()->id;
        $a->body = $this->html->clean($d['body']);
        $a->save();
        $this->audit->log('announcement.created', $a, new: ['course_id' => $a->course_id, 'notify' => $a->notify]);
        if ($a->notify) {
            SendAnnouncement::dispatch($a->id)->delay($a->publish_at->isFuture() ? $a->publish_at : null);
        }

        return $a;
    }

    // course announcements
    public function forCourse(Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);

        return response()->json(['data' => $this->visible(Announcement::where('course_id', $course->id)->with('author:id,name'))->get()->map(fn ($a) => $this->row($a))]);
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => $this->row($this->make($request, $course, $this->validated($request)))], 201);
    }

    public function destroy(Announcement $announcement): JsonResponse
    {
        abort_unless($announcement->course_id, 404);
        Gate::authorize('manage', $announcement->course_id ? Course::findOrFail($announcement->course_id) : abort(404));
        $announcement->delete();

        return response()->json(['message' => 'Announcement removed.']);
    }

    // platform announcements
    public function platform(Request $request): JsonResponse
    {
        $u = $request->user();
        $audiences = array_filter(['all', $u->hasRole('student') ? 'students' : null, $u->hasRole('lecturer') ? 'lecturers' : null]);

        return response()->json(['data' => $this->visible(Announcement::whereNull('course_id')->whereIn('audience', $audiences)->with('author:id,name'))->limit(20)->get()->map(fn ($a) => $this->row($a))]);
    }

    public function storePlatform(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->row($this->make($request, null, $this->validated($request)))], 201);
    }

    public function destroyPlatform(Announcement $announcement): JsonResponse
    {
        abort_unless($announcement->course_id === null, 404);
        $announcement->delete();

        return response()->json(['message' => 'Announcement removed.']);
    }
}
