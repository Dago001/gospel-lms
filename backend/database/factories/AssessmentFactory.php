<?php

namespace Database\Factories;

use App\Models\Assessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assessment> */
class AssessmentFactory extends Factory
{
    public function definition(): array
    {
        return ['kind' => 'quiz', 'title' => 'Quiz '.fake()->numberBetween(1, 999), 'status' => 'published', 'version' => 1, 'published_at' => now(), 'time_limit_minutes' => 30, 'max_attempts' => 1,
            'pass_mark_percent' => 50, 'weight' => 10, 'is_required' => true, 'results_release' => 'immediate', 'review_policy' => 'never'];
    }
}
