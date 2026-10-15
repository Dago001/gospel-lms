<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Course lifecycle: draft -> pending_review -> approved -> published -> archived. */
class CourseWorkflow
{
    public function __construct(private AuditLogger $audit, private CourseVersioning $versions) {}

    public function submit(Course $course): Course
    {
        $this->expect($course, ['draft']);
        $problems = $this->readinessProblems($course);
        if ($problems) {
            throw new DomainException('The course is not ready for review.', 'course_not_ready', 422, $problems);
        }

        return $this->move($course, 'pending_review', 'course.submitted', ['review_notes' => null]);
    }

    public function approve(Course $course, User $by): Course
    {
        $this->expect($course, ['pending_review']);
        $this->versions->snapshot($course, 'Approved');

        return $this->move($course, 'approved', 'course.approved', ['approved_by' => $by->id, 'approved_at' => now(), 'review_notes' => null]);
    }

    public function reject(Course $course, string $notes): Course
    {
        $this->expect($course, ['pending_review']);

        return $this->move($course, 'draft', 'course.rejected', ['review_notes' => $notes], $notes);
    }

    public function publish(Course $course): Course
    {
        $this->expect($course, ['approved']);
        $this->versions->snapshot($course, 'Published');

        return $this->move($course, 'published', 'course.published', ['published_at' => now(), 'scheduled_publish_at' => null, 'archived_at' => null]);
    }

    public function schedule(Course $course, CarbonInterface $at): Course
    {
        $this->expect($course, ['approved']);
        if ($at->lte(now())) {
            throw new DomainException('The publication time must be in the future.', 'invalid_schedule');
        }
        $course->forceFill(['scheduled_publish_at' => $at])->save();
        $this->audit->log('course.scheduled', $course, new: ['scheduled_publish_at' => $at->toIso8601String()]);

        return $course;
    }

    public function archive(Course $course): Course
    {
        $this->expect($course, ['draft', 'approved', 'published']);

        return $this->move($course, 'archived', 'course.archived', ['archived_at' => now(), 'scheduled_publish_at' => null]);
    }

    public function restore(Course $course): Course
    {
        $this->expect($course, ['archived']);

        return $this->move($course, 'draft', 'course.restored', ['archived_at' => null]);
    }

    /** Publishes approved courses whose scheduled time has arrived. Called by the scheduler; idempotent. */
    public function publishDue(): int
    {
        $n = 0;
        Course::where('status', 'approved')->whereNotNull('scheduled_publish_at')->where('scheduled_publish_at', '<=', now())
            ->each(function (Course $c) use (&$n) {
                try {
                    $this->publish($c);
                    $n++;
                } catch (DomainException) {
                    // Another worker published it first; nothing to do.
                }
            });

        return $n;
    }

    /** @return string[] human-readable reasons the course cannot be submitted */
    public function readinessProblems(Course $course): array
    {
        $p = [];
        if (! $course->category_id) $p[] = 'Choose a category.';
        if (blank($course->summary)) $p[] = 'Add a short summary.';
        if (blank($course->description)) $p[] = 'Add a description.';
        if (empty($course->objectives)) $p[] = 'Add at least one learning objective.';
        if (! $course->instructors()->exists()) $p[] = 'Assign a lecturer.';
        if (! $course->lessons()->exists()) $p[] = 'Add at least one lesson.';
        if ($course->lessons()->where('type', 'video')->whereNull('video_source')->exists()) $p[] = 'Every video lesson needs a video source.';

        return $p;
    }

    private function expect(Course $course, array $statuses): void
    {
        if (! in_array($course->status, $statuses, true)) {
            throw new DomainException("This action is not allowed while the course is {$course->status}.", 'invalid_transition', 409);
        }
    }

    private function move(Course $course, string $to, string $action, array $extra = [], ?string $reason = null): Course
    {
        return DB::transaction(function () use ($course, $to, $action, $extra, $reason) {
            $locked = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $locked->forceFill(['status' => $to] + $extra)->save();
            $this->audit->log($action, $locked, ['status' => $from], ['status' => $to], $reason);

            return $locked;
        });
    }
}
