<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

class EnrolmentService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Idempotent: enrolling twice returns the existing enrolment. Backed by UNIQUE(course_id, user_id).
     * Payment-sourced enrolment is only created by the payment service after server-side verification.
     */
    public function enrol(User $user, Course $course, string $source, ?string $orderItemId = null, bool $enforceRules = true): CourseEnrollment
    {
        if ($enforceRules) {
            if (! $course->isPublished()) {
                throw new DomainException('This course is not open for enrolment.', 'course_unavailable', 409);
            }
            $missing = $course->prerequisites()->whereDoesntHave('enrollments', fn ($q) => $q->where('user_id', $user->id)->where('status', 'completed'))->pluck('title')->all();
            if ($missing) {
                throw new DomainException('Complete the prerequisite courses first.', 'prerequisites_unmet', 422, $missing);
            }
        }
        if ($existing = CourseEnrollment::where(['course_id' => $course->id, 'user_id' => $user->id])->first()) {
            if ($existing->status === 'cancelled') {
                $existing->update(['status' => 'active', 'source' => $source, 'order_item_id' => $orderItemId, 'enrolled_at' => now()]);
                $this->audit->log('enrolment.reinstated', $existing, actorId: $user->id);
            }

            return $existing;
        }
        try {
            $e = CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $user->id, 'status' => 'active', 'source' => $source,
                'order_item_id' => $orderItemId, 'enrolled_at' => now()]);
            $this->audit->log('enrolment.created', $e, new: ['source' => $source, 'course_id' => $course->id], actorId: request()?->user()?->id ?? $user->id);

            return $e;
        } catch (UniqueConstraintViolationException) {
            return CourseEnrollment::where(['course_id' => $course->id, 'user_id' => $user->id])->firstOrFail(); // lost a race; same result
        }
    }

    public function enrolFree(User $user, Course $course): CourseEnrollment
    {
        if (! $user->hasVerifiedEmail()) {
            throw new DomainException('Verify your email address before enrolling.', 'email_unverified', 403);
        }
        if (! app(CoursePricing::class)->effective($course)['is_free']) {
            throw new DomainException('This course requires payment.', 'payment_required', 402);
        }

        return $this->enrol($user, $course, 'free');
    }

    public function userHasAccess(User $user, Course $course): bool
    {
        return CourseEnrollment::where(['course_id' => $course->id, 'user_id' => $user->id])->whereIn('status', ['active', 'completed'])->exists();
    }
}
