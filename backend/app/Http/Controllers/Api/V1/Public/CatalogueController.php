<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseSerializer;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogueController extends Controller
{
    /** Non-secret site information for the public shell. */
    public function site(): JsonResponse
    {
        $a = \App\Services\Settings::academy();

        return response()->json(['data' => ['name' => $a['name'], 'support_email' => $a['support_email'], 'maintenance_message' => \App\Services\Settings::get('site.maintenance_message'),
            'refund_window_days' => \App\Services\Settings::get('policy.refund_window_days')]]);
    }

    public function categories(): JsonResponse
    {
        $cats = Category::query()->withCount(['courses' => fn ($q) => $q->where('status', 'published')])->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $cats->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'description' => $c->description, 'course_count' => $c->courses_count])]);
    }

    public function courses(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced'])], 'price' => ['nullable', Rule::in(['free', 'paid'])],
            'sort' => ['nullable', Rule::in(['newest', 'title', 'price_asc', 'price_desc'])], 'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $q = Course::query()->where('status', 'published')->with(['category', 'instructors.profile'])
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'ilike', '%'.addcslashes($s, '%_\\').'%')->orWhere('summary', 'ilike', '%'.addcslashes($s, '%_\\').'%')))
            ->when($f['category'] ?? null, fn ($q, $s) => $q->whereHas('category', fn ($w) => $w->where('slug', $s)))
            ->when($f['level'] ?? null, fn ($q, $s) => $q->where('level', $s))
            ->when($f['price'] ?? null, fn ($q, $s) => $s === 'free' ? $q->where('price_minor', 0) : $q->where('price_minor', '>', 0));
        match ($f['sort'] ?? 'newest') {
            'title' => $q->orderBy('title'), 'price_asc' => $q->orderBy('price_minor')->orderBy('title'),
            'price_desc' => $q->orderByDesc('price_minor')->orderBy('title'), default => $q->orderByDesc('published_at'),
        };
        $page = $q->paginate($f['per_page'] ?? 12);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => CourseSerializer::card($c))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function course(string $slug): JsonResponse
    {
        $c = Course::where('slug', $slug)->where('status', 'published')
            ->with(['category', 'instructors.profile', 'prerequisites', 'modules.lessons'])->firstOrFail();

        return response()->json(['data' => CourseSerializer::publicDetail($c)]);
    }

    public function lecturers(): JsonResponse
    {
        $users = User::query()->whereHas('profile', fn ($q) => $q->where('public_profile', true))->whereHas('roles', fn ($q) => $q->where('slug', 'lecturer'))
            ->where('status', 'active')->with('profile')->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'headline' => $u->profile->headline, 'bio' => $u->profile->bio])]);
    }

    public function lecturer(string $id): JsonResponse
    {
        $u = User::whereKey($id)->where('status', 'active')->whereHas('profile', fn ($q) => $q->where('public_profile', true))
            ->whereHas('roles', fn ($q) => $q->where('slug', 'lecturer'))->with('profile')->firstOrFail();
        $courses = Course::where('status', 'published')->whereHas('instructors', fn ($q) => $q->where('users.id', $u->id))->with(['category', 'instructors.profile'])->get();

        return response()->json(['data' => ['id' => $u->id, 'name' => $u->name, 'headline' => $u->profile->headline, 'bio' => $u->profile->bio,
            'courses' => $courses->map(fn ($c) => CourseSerializer::card($c))->values()]]);
    }
}
