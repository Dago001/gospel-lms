<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Course\CourseRequest;
use App\Http\Requests\Course\PricingRequest;
use App\Http\Resources\CourseSerializer;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CourseVersioning;
use App\Services\CourseWorkflow;
use App\Services\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function __construct(private HtmlSanitizer $html, private CourseVersioning $versions, private CourseWorkflow $workflow, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:20'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $u = $request->user();
        $page = Course::query()->with(['category', 'instructors.profile', 'prerequisites'])
            ->unless($u->hasPermission('courses.manage-all'), fn ($q) => $q->whereHas('instructors', fn ($w) => $w->where('users.id', $u->id)))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where('title', \App\Support\DbCompat::like(), '%'.addcslashes($s, '%_\\').'%'))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('updated_at')->paginate($f['per_page'] ?? 20);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => CourseSerializer::manage($c) + ['readiness_problems' => $c->status === 'draft' ? $this->workflow->readinessProblems($c) : []])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function store(CourseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $course = DB::transaction(function () use ($data, $user) {
            $c = new Course(collect($data)->except('instructor_ids', 'description')->all());
            $c->description = $this->html->clean($data['description'] ?? null);
            $c->slug = $this->uniqueSlug($data['title']);
            $c->created_by = $user->id;
            $c->save();
            $ids = $user->hasPermission('courses.manage-all') && ! empty($data['instructor_ids']) ? $this->validLecturers($data['instructor_ids']) : [$user->id];
            $c->instructors()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['role' => $i === 0 ? 'lead' : 'assistant']])->all());
            $this->audit->log('course.created', $c, new: ['title' => $c->title]);

            return $c;
        });

        return response()->json(['data' => CourseSerializer::manage($course->fresh(['category', 'instructors.profile', 'prerequisites']))], 201);
    }

    public function show(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);
        $course->load(['category', 'instructors.profile', 'prerequisites', 'modules.lessons.resources']);

        return response()->json(['data' => CourseSerializer::manage($course) + [
            'readiness_problems' => $this->workflow->readinessProblems($course),
            'modules' => $course->modules->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'description' => $m->description, 'position' => $m->position,
                'lessons' => $m->lessons->map(fn ($l) => CourseSerializer::manageLesson($l))->values()])->values(),
        ]]);
    }

    public function update(CourseRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $data = $request->validated();
        if (isset($data['instructor_ids'])) {
            Gate::authorize('administer', $course);
        }
        DB::transaction(function () use ($course, $data) {
            $course->fill(collect($data)->except('instructor_ids', 'description')->all());
            if (array_key_exists('description', $data)) {
                $course->description = $this->html->clean($data['description']);
            }
            $course->save();
            if (isset($data['instructor_ids'])) {
                $ids = $this->validLecturers($data['instructor_ids']);
                $course->instructors()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['role' => $i === 0 ? 'lead' : 'assistant']])->all());
            }
            $this->versions->snapshot($course, 'Edited');
            $this->audit->log('course.updated', $course, new: array_keys($data));
        });

        return response()->json(['data' => CourseSerializer::manage($course->fresh(['category', 'instructors.profile', 'prerequisites']))]);
    }

    public function destroy(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);
        if ($course->status !== 'draft' || $course->enrollments()->exists()) {
            throw new DomainException('Only unused draft courses can be deleted. Archive the course instead.', 'cannot_delete', 409);
        }
        $this->audit->log('course.deleted', $course, ['title' => $course->title]);
        $course->delete();

        return response()->json(['message' => 'Course deleted.']);
    }

    public function pricing(PricingRequest $request, Course $course): JsonResponse
    {
        Gate::authorize('administer', $course);
        $old = $course->only(['price_minor', 'discount_price_minor', 'discount_starts_at', 'discount_ends_at']);
        $in = $request->validated();
        $course->forceFill([
            'price_minor' => $in['price_minor'], 'discount_price_minor' => $in['discount_price_minor'] ?? null,
            'discount_starts_at' => $in['discount_starts_at'] ?? null, 'discount_ends_at' => $in['discount_ends_at'] ?? null,
        ])->save();
        $this->audit->log('course.pricing_changed', $course, $old, $course->only(array_keys($old)));

        return response()->json(['data' => CourseSerializer::manage($course->fresh(['category', 'instructors.profile', 'prerequisites']))]);
    }

    public function prerequisites(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('edit', $course);
        $ids = $request->validate(['course_ids' => ['present', 'array', 'max:10'], 'course_ids.*' => ['uuid', 'distinct', 'exists:courses,id']])['course_ids'];
        foreach ($ids as $id) {
            if ($id === $course->id || $this->dependsOn($id, $course->id)) {
                throw new DomainException('That prerequisite would create a circular dependency.', 'circular_prerequisite');
            }
        }
        $course->prerequisites()->sync($ids);
        $this->audit->log('course.prerequisites_changed', $course, new: ['course_ids' => $ids]);

        return response()->json(['data' => $course->prerequisites()->get(['courses.id', 'title'])]);
    }

    public function versions(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return response()->json(['data' => $course->versions()->with([])->get()->map(fn ($v) => [
            'version' => $v->version, 'note' => $v->note, 'changed_by' => $v->changed_by, 'created_at' => $v->created_at->toIso8601String(), 'snapshot' => $v->snapshot])]);
    }

    // ---- workflow ----
    public function submit(Course $course): JsonResponse
    {
        Gate::authorize('manage', $course);

        return $this->state($this->workflow->submit($course));
    }

    public function approve(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('approve', $course);

        return $this->state($this->workflow->approve($course, $request->user()));
    }

    public function reject(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('approve', $course);
        $notes = $request->validate(['notes' => ['required', 'string', 'max:2000']])['notes'];

        return $this->state($this->workflow->reject($course, $notes));
    }

    public function publish(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('approve', $course);
        $at = $request->validate(['publish_at' => ['nullable', 'date']])['publish_at'] ?? null;

        return $this->state($at ? $this->workflow->schedule($course, \Illuminate\Support\Carbon::parse($at)) : $this->workflow->publish($course));
    }

    public function archive(Course $course): JsonResponse
    {
        Gate::authorize('approve', $course);

        return $this->state($this->workflow->archive($course));
    }

    public function restore(Course $course): JsonResponse
    {
        Gate::authorize('approve', $course);

        return $this->state($this->workflow->restore($course));
    }

    private function state(Course $c): JsonResponse
    {
        return response()->json(['data' => ['id' => $c->id, 'status' => $c->status, 'scheduled_publish_at' => $c->scheduled_publish_at?->toIso8601String(), 'review_notes' => $c->review_notes]]);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'course';
        $slug = $base;
        for ($i = 2; Course::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** Only active lecturers can be assigned to teach. */
    private function validLecturers(array $ids): array
    {
        $ok = User::whereIn('id', $ids)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('slug', 'lecturer'))->pluck('id')->all();
        if (count($ok) !== count($ids)) {
            throw new DomainException('Instructors must be active lecturers.', 'invalid_instructor');
        }

        return $ids;
    }

    private function dependsOn(string $courseId, string $targetId, array $seen = []): bool
    {
        if (in_array($courseId, $seen, true)) {
            return false;
        }
        $seen[] = $courseId;
        foreach (DB::table('course_prerequisites')->where('course_id', $courseId)->pluck('prerequisite_id') as $pre) {
            if ($pre === $targetId || $this->dependsOn($pre, $targetId, $seen)) {
                return true;
            }
        }

        return false;
    }
}
