<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched once, after commit, when the backend decides a lesson is complete. The completion engine listens (Phase 10). */
class LessonCompleted
{
    use Dispatchable;

    public function __construct(public string $userId, public string $lessonId, public string $courseId) {}
}
