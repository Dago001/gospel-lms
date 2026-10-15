<?php

namespace App\Services\Video;

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonCompletionRecord;
use App\Models\User;

/** Decides which lessons a learner may open. The browser never decides this. */
class ProgressionService
{
    /** Lessons in curriculum order: [lesson rows with module/lesson positions]. */
    public function ordered(Course $course)
    {
        return Lesson::query()->where('lessons.course_id', $course->id)->join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
            ->orderBy('course_modules.position')->orderBy('lessons.position')->select('lessons.*')->get();
    }

    /** @return array<string,array{locked:bool,completed:bool}> keyed by lesson id */
    public function states(User $user, Course $course): array
    {
        $done = LessonCompletionRecord::where(['user_id' => $user->id, 'course_id' => $course->id])->pluck('lesson_id')->flip();
        $out = [];
        $blocked = false;
        foreach ($this->ordered($course) as $l) {
            $completed = $done->has($l->id);
            $out[$l->id] = ['locked' => $course->sequential_progression && $blocked && ! $l->is_preview, 'completed' => $completed];
            if ($l->is_required && ! $completed) {
                $blocked = true;       // everything after the first unfinished required lesson is locked
            }
        }

        return $out;
    }

    public function isUnlocked(User $user, Lesson $lesson): bool
    {
        return ! ($this->states($user, $lesson->course)[$lesson->id]['locked'] ?? false);
    }

    public function assertUnlocked(User $user, Lesson $lesson): void
    {
        if (! $this->isUnlocked($user, $lesson)) {
            throw new DomainException('Complete the earlier lessons to unlock this one.', 'lesson_locked', 423);
        }
    }

    /** Real progress: completed required lessons / required lessons. Null when the course has no required lessons. */
    public function summary(User $user, Course $course): array
    {
        $lessons = $this->ordered($course);
        $states = $this->states($user, $course);
        $required = $lessons->where('is_required', true);
        $doneRequired = $required->filter(fn ($l) => $states[$l->id]['completed'])->count();
        $next = $lessons->first(fn ($l) => ! $states[$l->id]['completed']);

        return ['required_total' => $required->count(), 'required_completed' => $doneRequired,
            'percent' => $required->count() ? round($doneRequired / $required->count() * 100, 1) : null, 'next_lesson_id' => $next?->id, 'states' => $states];
    }
}
