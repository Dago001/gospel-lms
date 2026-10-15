<?php

namespace Database\Factories;

use App\Models\Assignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => 'Essay: '.fake()->words(3, true), 'instructions' => '<p>Write.</p>', 'max_score' => 100, 'pass_mark' => 50, 'weight' => 20, 'is_required' => true,
            'late_policy' => 'reject', 'late_penalty_percent' => 0, 'allow_resubmission' => false, 'max_attempts' => 1, 'max_files' => 3, 'max_file_mb' => 5,
            'allowed_extensions' => ['pdf', 'docx', 'txt'], 'status' => 'published', 'published_at' => now(), 'due_at' => now()->addWeek()];
    }
}
