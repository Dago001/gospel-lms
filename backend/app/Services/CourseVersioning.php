<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseVersion;
use Illuminate\Support\Facades\DB;

class CourseVersioning
{
    /** Records an immutable snapshot of the course and its curriculum, then advances the version counter. */
    public function snapshot(Course $course, ?string $note = null, ?string $userId = null): CourseVersion
    {
        return DB::transaction(function () use ($course, $note, $userId) {
            $course = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            $course->load('modules.lessons');
            $version = $course->version;
            $snap = CourseVersion::create([
                'course_id' => $course->id, 'version' => $version, 'note' => $note, 'changed_by' => $userId ?? request()?->user()?->id,
                'snapshot' => [
                    'course' => $course->only(['title', 'summary', 'description', 'objectives', 'requirements', 'level', 'status', 'price_minor', 'currency', 'video_completion_percent']),
                    'modules' => $course->modules->map(fn ($m) => [
                        'id' => $m->id, 'title' => $m->title, 'position' => $m->position,
                        'lessons' => $m->lessons->map(fn ($l) => $l->only(['id', 'title', 'type', 'position', 'duration_seconds', 'is_required', 'is_preview']))->all(),
                    ])->all(),
                ],
            ]);
            $course->forceFill(['version' => $version + 1])->save();

            return $snap;
        });
    }
}
