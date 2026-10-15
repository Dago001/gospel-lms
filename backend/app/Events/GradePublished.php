<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A graded component became visible or changed. The completion engine re-evaluates the learner (Phase 10). */
class GradePublished
{
    use Dispatchable;

    public function __construct(public string $userId, public string $courseId, public string $kind, public string $subjectId) {}
}
