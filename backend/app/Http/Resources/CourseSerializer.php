<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CoursePricing;

/** Shapes course data for each audience. Public output never includes lesson bodies, video ids or private notes. */
final class CourseSerializer
{
    public static function price(Course $c): array
    {
        return app(CoursePricing::class)->effective($c);
    }

    public static function instructor(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'headline' => $u->profile?->headline, 'role' => $u->pivot->role ?? null];
    }

    public static function card(Course $c): array
    {
        return [
            'id' => $c->id, 'slug' => $c->slug, 'title' => $c->title, 'summary' => $c->summary, 'level' => $c->level,
            'category' => $c->category ? ['slug' => $c->category->slug, 'name' => $c->category->name] : null,
            'duration_minutes' => $c->durationMinutes(), 'price' => self::price($c),
            'instructors' => $c->instructors->map(fn ($u) => self::instructor($u))->values(),
        ];
    }

    public static function publicDetail(Course $c): array
    {
        return self::card($c) + [
            'description' => $c->description, 'objectives' => $c->objectives, 'requirements' => $c->requirements,
            'prerequisites' => $c->prerequisites->map(fn ($p) => ['slug' => $p->slug, 'title' => $p->title])->values(),
            'curriculum' => $c->modules->map(fn ($m) => [
                'id' => $m->id, 'title' => $m->title, 'description' => $m->description,
                'lessons' => $m->lessons->map(fn ($l) => self::lessonOutline($l))->values(),
            ])->values(),
        ];
    }

    public static function lessonOutline(Lesson $l): array
    {
        return ['id' => $l->id, 'title' => $l->title, 'type' => $l->type, 'duration_seconds' => $l->duration_seconds,
            'is_preview' => $l->is_preview, 'is_required' => $l->is_required, 'position' => $l->position];
    }

    public static function manage(Course $c): array
    {
        return self::card($c) + [
            'status' => $c->status, 'review_notes' => $c->review_notes, 'version' => $c->version,
            'description' => $c->description, 'objectives' => $c->objectives, 'requirements' => $c->requirements,
            'video_completion_percent' => $c->video_completion_percent, 'duration_minutes_override' => $c->duration_minutes_override,
            'scheduled_publish_at' => $c->scheduled_publish_at?->toIso8601String(), 'published_at' => $c->published_at?->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(), 'category_id' => $c->category_id,
            'prerequisites' => $c->prerequisites->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])->values(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    public static function manageLesson(Lesson $l): array
    {
        return self::lessonOutline($l) + ['content' => $l->content, 'video_source' => $l->video_source, 'youtube_id' => $l->youtube_id,
            'required_completion_percent' => $l->required_completion_percent,
            'resources' => $l->relationLoaded('resources') ? $l->resources->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'name' => $r->original_name, 'size_bytes' => $r->size_bytes, 'mime' => $r->mime])->values() : []];
    }
}
