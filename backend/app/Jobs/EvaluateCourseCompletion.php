<?php

namespace App\Jobs;

use App\Models\Course;
use App\Models\User;
use App\Services\CompletionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Re-evaluates one learner in one course. Unique per pair, idempotent, retried with backoff. */
class EvaluateCourseCompletion implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 30;

    public function __construct(public string $userId, public string $courseId, public string $trigger) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.$this->courseId;
    }

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(CompletionEngine $engine): void
    {
        $user = User::find($this->userId);
        $course = Course::find($this->courseId);
        if ($user && $course) {
            $engine->run($user, $course, $this->trigger);
        }
    }
}
