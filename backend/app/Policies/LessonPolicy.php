<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;
use App\Services\EnrolmentService;

class LessonPolicy
{
    public function view(User $user, Lesson $lesson): bool
    {
        $course = $lesson->course;
        if ($user->hasPermission('courses.manage-all') || ($user->hasPermission('courses.manage-own') && $course->isTaughtBy($user))) {
            return true;
        }
        if (! $course->isPublished() && $course->status !== 'archived') {
            return false;
        }
        if ($lesson->is_preview && $course->isPublished()) {
            return true;
        }

        return app(EnrolmentService::class)->userHasAccess($user, $course);
    }
}
