<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Course> */
class CourseFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true).' '.fake()->numberBetween(1, 9999);

        return [
            'slug' => Str::slug($title), 'title' => ucfirst($title), 'summary' => 'A short summary.', 'description' => '<p>Description.</p>',
            'objectives' => ['Understand the basics'], 'requirements' => [], 'level' => 'beginner', 'status' => 'draft',
            'price_minor' => 0, 'currency' => 'NGN', 'video_completion_percent' => 90, 'version' => 1,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published', 'published_at' => now()]);
    }

    public function priced(int $minor): static
    {
        return $this->state(fn () => ['price_minor' => $minor]);
    }

    /** Course with one module and $lessons lessons, a category, and the given instructor attached. */
    public function withCurriculum(int $lessons = 2, ?\App\Models\User $instructor = null): static
    {
        return $this->afterCreating(function (Course $c) use ($lessons, $instructor) {
            $c->category_id ??= Category::firstOrCreate(['slug' => 'theology'], ['name' => 'Theology'])->id;
            $c->save();
            $m = new CourseModule(['title' => 'Module 1']);
            $m->course_id = $c->id;
            $m->position = 1;
            $m->save();
            for ($i = 1; $i <= $lessons; $i++) {
                $l = new Lesson(['title' => "Lesson $i", 'type' => 'text', 'content' => '<p>Body</p>', 'duration_seconds' => 600]);
                $l->course_id = $c->id;
                $l->module_id = $m->id;
                $l->position = $i;
                $l->save();
            }
            if ($instructor) {
                $c->instructors()->attach($instructor->id, ['role' => 'lead']);
            }
        });
    }
}
