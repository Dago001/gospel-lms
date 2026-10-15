<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /** Staff who run the course: assigned lecturers or anyone with global course management. */
    public function manage(User $user, Course $course): bool
    {
        return $user->hasPermission('courses.manage-all')
            || ($user->hasPermission('courses.manage-own') && $course->isTaughtBy($user));
    }

    /** Edit content: not while a review is in progress or after archiving. */
    public function edit(User $user, Course $course): bool
    {
        return $this->manage($user, $course) && in_array($course->status, Course::EDITABLE_STATUSES, true);
    }

    public function approve(User $user, Course $course): bool
    {
        return $user->hasPermission('courses.approve');
    }

    public function administer(User $user, Course $course): bool
    {
        return $user->hasPermission('courses.manage-all');
    }

    /** Read the full curriculum: enrolled students and course staff. */
    public function learn(User $user, Course $course): bool
    {
        return $this->manage($user, $course) || app(\App\Services\EnrolmentService::class)->userHasAccess($user, $course);
    }
}
