<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Course\LessonRequest;
use App\Http\Resources\CourseSerializer;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Services\AuditLogger;
use App\Services\HtmlSanitizer;
use App\Services\MalwareScanner;
use App\Support\YouTube;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CurriculumController extends Controller
{
    public function __construct(private HtmlSanitizer $html, private AuditLogger $audit) {}

    // ---- modules ----
    public function storeModule(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $d = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000']]);
        $m = DB::transaction(function () use ($course, $d) {
            Course::whereKey($course->id)->lockForUpdate()->first(); // serialise position allocation
            $m = new CourseModule($d);
            $m->course_id = $course->id;
            $m->position = ($course->modules()->max('position') ?? 0) + 1;
            $m->save();

            return $m;
        });

        return response()->json(['data' => ['id' => $m->id, 'title' => $m->title, 'description' => $m->description, 'position' => $m->position, 'lessons' => []]], 201);
    }

    public function updateModule(Request $request, CourseModule $module): JsonResponse
    {
        Gate::authorize('edit', $module->course);
        $module->update($request->validate(['title' => ['sometimes', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000']]));

        return response()->json(['data' => ['id' => $module->id, 'title' => $module->title, 'description' => $module->description, 'position' => $module->position]]);
    }

    public function destroyModule(CourseModule $module): JsonResponse
    {
        Gate::authorize('edit', $module->course);
        $this->guardLocked($module->course);
        $module->delete();
        $this->audit->log('module.deleted', $module);

        return response()->json(['message' => 'Module deleted.']);
    }

    public function reorderModules(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid', 'distinct']])['ids'];
        $this->reorder($course->modules()->pluck('id')->all(), $ids, 'course_modules');

        return response()->json(['message' => 'Order saved.']);
    }

    // ---- lessons ----
    public function storeLesson(LessonRequest $request, CourseModule $module): JsonResponse
    {
        $course = $module->course;
        Gate::authorize('edit', $course);
        $lesson = DB::transaction(function () use ($request, $module, $course) {
            CourseModule::whereKey($module->id)->lockForUpdate()->first();
            $l = new Lesson;
            $this->fillLesson($l, $request->validated());
            $l->course_id = $course->id;
            $l->module_id = $module->id;
            $l->position = ($module->lessons()->max('position') ?? 0) + 1;
            $l->save();

            return $l;
        });

        return response()->json(['data' => CourseSerializer::manageLesson($lesson->load('resources'))], 201);
    }

    public function updateLesson(LessonRequest $request, Lesson $lesson): JsonResponse
    {
        Gate::authorize('edit', $lesson->course);
        $this->fillLesson($lesson, $request->validated());
        $lesson->save();

        return response()->json(['data' => CourseSerializer::manageLesson($lesson->load('resources'))]);
    }

    public function destroyLesson(Lesson $lesson): JsonResponse
    {
        Gate::authorize('edit', $lesson->course);
        $this->guardLocked($lesson->course);
        $this->audit->log('lesson.deleted', $lesson, ['title' => $lesson->title]);
        $lesson->delete();

        return response()->json(['message' => 'Lesson deleted.']);
    }

    public function reorderLessons(Request $request, CourseModule $module): JsonResponse
    {
        Gate::authorize('edit', $module->course);
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid', 'distinct']])['ids'];
        $this->reorder($module->lessons()->pluck('id')->all(), $ids, 'lessons');

        return response()->json(['message' => 'Order saved.']);
    }

    // ---- study documents ----
    public function uploadResource(Request $request, Lesson $lesson, MalwareScanner $scanner): JsonResponse
    {
        Gate::authorize('edit', $lesson->course);
        $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,jpg,jpeg,png'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
        ]);
        $file = $request->file('file');
        $scanner->assertClean($file);
        $path = $file->storeAs("lesson-resources/{$lesson->course_id}", Str::uuid().'.'.$file->extension(), 'private'); // client filename is never used for storage
        $r = LessonResource::create([
            'lesson_id' => $lesson->id, 'title' => $request->input('title'), 'path' => $path,
            'original_name' => Str::limit(preg_replace('/[^\w.\- ]+/u', '_', $file->getClientOriginalName()), 200, ''),
            'mime' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'requires_acknowledgement' => $request->boolean('requires_acknowledgement'),
        ]);

        return response()->json(['data' => ['id' => $r->id, 'title' => $r->title, 'name' => $r->original_name, 'size_bytes' => $r->size_bytes, 'mime' => $r->mime]], 201);
    }

    public function destroyResource(LessonResource $resource): JsonResponse
    {
        Gate::authorize('edit', $resource->lesson->course);
        Storage::disk('private')->delete($resource->path);
        $resource->delete();

        return response()->json(['message' => 'Resource removed.']);
    }

    // ---- helpers ----
    private function fillLesson(Lesson $l, array $d): void
    {
        $l->fill(collect($d)->except(['youtube_url', 'content'])->all());
        if (array_key_exists('content', $d)) {
            $l->content = $this->html->clean($d['content']);
        }
        if (array_key_exists('youtube_url', $d)) {
            $id = $d['youtube_url'] ? YouTube::extractId($d['youtube_url']) : null;
            $l->youtube_id = $id;
            $l->video_source = $id ? 'youtube' : ($l->video_source === 'hosted' ? 'hosted' : null);
        }
        if (($d['type'] ?? $l->type) !== 'video' && $l->video_source === 'youtube') {
            $l->youtube_id = null;
            $l->video_source = null;
        }
    }

    /** Content that students may have progressed on cannot be destroyed; archive the course instead. */
    private function guardLocked(Course $course): void
    {
        if ($course->enrollments()->exists()) {
            throw new DomainException('This course has enrolled students, so content cannot be deleted.', 'content_locked', 409);
        }
    }

    /** Replace sibling ordering atomically; positions are parked first so the unique constraint is never violated mid-update. */
    private function reorder(array $current, array $requested, string $table): void
    {
        if (count($requested) !== count($current) || array_diff($requested, $current) || array_diff($current, $requested)) {
            throw new DomainException('The order must list every item exactly once.', 'invalid_order');
        }
        DB::transaction(function () use ($requested, $table) {
            // Two passes: park every item at a high temporary position first, so the unique(position) check never sees a clash
            // mid-update. (PostgreSQL could defer the check to commit; MySQL cannot.)
            foreach ($requested as $i => $id) {
                DB::table($table)->where('id', $id)->update(['position' => 1000000 + $i]);
            }
            foreach ($requested as $i => $id) {
                DB::table($table)->where('id', $id)->update(['position' => $i + 1, 'updated_at' => now()]);
            }
        });
    }
}
