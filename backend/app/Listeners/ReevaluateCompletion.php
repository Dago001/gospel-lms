<?php

namespace App\Listeners;

use App\Events\GradePublished;
use App\Events\LessonCompleted;
use App\Jobs\EvaluateCourseCompletion;

/** Any relevant learning or grading change re-runs the completion check for that learner and course. */
class ReevaluateCompletion
{
    public function handle(LessonCompleted|GradePublished $e): void
    {
        EvaluateCourseCompletion::dispatch($e->userId, $e->courseId, class_basename($e));
    }
}
