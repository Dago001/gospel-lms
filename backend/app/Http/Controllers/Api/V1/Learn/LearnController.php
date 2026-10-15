<?php

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseSerializer;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Services\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class LearnController extends Controller
{
    public function myCourses(Request $request): JsonResponse
    {
        $rows = CourseEnrollment::where('user_id', $request->user()->id)->whereIn('status', ['active', 'completed'])
            ->with(['course.category', 'course.instructors.profile'])->withCount([])->latest('enrolled_at')->get();

        return response()->json(['data' => $rows->map(fn ($e) => [
            'enrollment_id' => $e->id, 'status' => $e->status, 'enrolled_at' => $e->enrolled_at->toIso8601String(),
            'completed_at' => $e->completed_at?->toIso8601String(), 'lesson_count' => $e->course->lessons()->count(),
            'course' => CourseSerializer::card($e->course),
        ])]);
    }

    public function enrolFree(Request $request, Course $course, EnrolmentService $svc): JsonResponse
    {
        $e = $svc->enrolFree($request->user(), $course);

        return response()->json(['data' => ['enrollment_id' => $e->id, 'status' => $e->status]], $e->wasRecentlyCreated ? 201 : 200);
    }

    public function course(Course $course): JsonResponse
    {
        Gate::authorize('learn', $course);
        $course->load(['modules.lessons', 'instructors.profile', 'category']);

        return response()->json(['data' => CourseSerializer::publicDetail($course)]);
    }

    public function lesson(Lesson $lesson): JsonResponse
    {
        Gate::authorize('view', $lesson);
        $lesson->load(['resources', 'course']);

        return response()->json(['data' => CourseSerializer::lessonOutline($lesson) + [
            'content' => $lesson->content, 'video_source' => $lesson->video_source, 'youtube_id' => $lesson->youtube_id,
            'required_completion_percent' => $lesson->requiredCompletionPercent(),
            'resources' => $lesson->resources->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'name' => $r->original_name, 'size_bytes' => $r->size_bytes, 'mime' => $r->mime])->values(),
        ]]);
    }

    /** Authorised, short-lived access to a private study document. */
    public function downloadResource(LessonResource $resource)
    {
        Gate::authorize('view', $resource->lesson);
        $disk = Storage::disk('private');
        $name = $resource->original_name;
        if (config('filesystems.disks.private.driver') === 's3') {
            // Object storage: hand the browser a 5-minute signed URL; the bucket itself stays private.
            return redirect()->away($disk->temporaryUrl($resource->path, now()->addMinutes(5), ['ResponseContentDisposition' => 'attachment; filename="'.addslashes($name).'"']));
        }

        return $disk->download($resource->path, $name); // local driver: stream through this authorised request
    }
}
