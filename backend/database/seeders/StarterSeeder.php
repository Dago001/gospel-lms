<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * LOCAL DEVELOPMENT ONLY. Creates sample staff and student accounts and one starter course so the interface can be explored.
 * Refuses to run in production. Nothing here is real institutional content.
 */
class StarterSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('StarterSeeder refuses to run in production.');

            return;
        }
        $this->call(RolesAndPermissionsSeeder::class);
        $pw = Hash::make('Password-12345');
        $mk = function (string $email, string $name, string $role, bool $public = false) use ($pw) {
            $u = User::firstOrNew(['email' => $email]);
            $u->forceFill(['name' => $name, 'password' => $pw, 'email_verified_at' => now(), 'status' => 'active'])->save();
            $u->roles()->syncWithoutDetaching([Role::where('slug', $role)->first()->id]);
            $u->profile()->firstOrCreate([], ['public_profile' => $public, 'headline' => $public ? 'Dr Samuel Adeyemi' : null]);

            return $u;
        };
        $mk('admin@academy.test', 'Academy Administrator', 'admin');
        $mk('finance@academy.test', 'Finance Officer', 'finance');
        $lect = $mk('lecturer@academy.test', 'Dr Samuel Adeyemi', 'lecturer', true);
        $mk('student@academy.test', 'Adaeze Okafor', 'student');

        $cat = Category::firstOrCreate(['slug' => 'bible-studies'], ['name' => 'Bible Studies', 'description' => 'Study of the Scriptures']);
        $c = Course::firstOrNew(['slug' => 'introduction-to-the-gospels']);
        if (! $c->exists) {
            $c->forceFill(['title' => 'Introduction to the Gospels', 'summary' => 'A guided introduction to the four Gospels: who wrote them, who they were written for, and how each presents Jesus.', 'description' => '<p>This course introduces the four Gospels of the New Testament. You will read each Gospel in its historical setting, compare how Matthew, Mark, Luke and John present the life and teaching of Jesus, and learn practical methods for studying and teaching them.</p>',
                'objectives' => ['Read the four Gospels in context', 'Compare their portraits of Jesus'], 'requirements' => ['None'], 'level' => 'beginner', 'category_id' => $cat->id, 'status' => 'published', 'published_at' => now(), 'price_minor' => 0, 'currency' => 'NGN', 'video_completion_percent' => 90, 'version' => 1, 'created_by' => $lect->id])->save();
            $c->instructors()->attach($lect->id, ['role' => 'lead']);
            $m = new CourseModule(['title' => 'Module 1: Background']);
            $m->course_id = $c->id;
            $m->position = 1;
            $m->save();
            foreach (['Why four Gospels?', 'The world of the first century'] as $i => $t) {
                $l = new Lesson(['title' => $t, 'type' => 'text', 'content' => '<p>Welcome to this lesson. Read the assigned passages carefully, note the main themes, and then mark the lesson complete to continue to the next one.</p>', 'duration_seconds' => 600, 'is_preview' => $i === 0]);
                $l->course_id = $c->id;
                $l->module_id = $m->id;
                $l->position = $i + 1;
                $l->save();
            }
        }
    }
}
