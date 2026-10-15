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
 * LOCAL DEVELOPMENT ONLY. Creates clearly-labelled demo accounts and one demo course so the interface can be inspected.
 * Refuses to run in production. Nothing here is real institutional content.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }
        $this->call(RolesAndPermissionsSeeder::class);
        $pw = Hash::make('Password-12345');
        $mk = function (string $email, string $name, string $role, bool $public = false) use ($pw) {
            $u = User::firstOrNew(['email' => $email]);
            $u->forceFill(['name' => $name, 'password' => $pw, 'email_verified_at' => now(), 'status' => 'active'])->save();
            $u->roles()->syncWithoutDetaching([Role::where('slug', $role)->first()->id]);
            $u->profile()->firstOrCreate([], ['public_profile' => $public, 'headline' => $public ? '[DEMO] Lecturer' : null]);

            return $u;
        };
        $mk('admin@demo.test', '[DEMO] Administrator', 'admin');
        $mk('finance@demo.test', '[DEMO] Finance Officer', 'finance');
        $lect = $mk('lecturer@demo.test', '[DEMO] Lecturer', 'lecturer', true);
        $mk('student@demo.test', '[DEMO] Student', 'student');

        $cat = Category::firstOrCreate(['slug' => 'demo-bible-studies'], ['name' => 'Bible Studies', 'description' => 'Demo category']);
        $c = Course::firstOrNew(['slug' => 'demo-introduction-to-the-gospels']);
        if (! $c->exists) {
            $c->forceFill(['title' => '[DEMO] Introduction to the Gospels', 'summary' => 'Demonstration course used only for local development.', 'description' => '<p>This is demonstration content. Replace it with the academy\'s real course material.</p>',
                'objectives' => ['Read the four Gospels in context', 'Compare their portraits of Jesus'], 'requirements' => ['None'], 'level' => 'beginner', 'category_id' => $cat->id, 'status' => 'published', 'published_at' => now(), 'price_minor' => 0, 'currency' => 'NGN', 'video_completion_percent' => 90, 'version' => 1, 'created_by' => $lect->id])->save();
            $c->instructors()->attach($lect->id, ['role' => 'lead']);
            $m = new CourseModule(['title' => 'Module 1: Background']);
            $m->course_id = $c->id;
            $m->position = 1;
            $m->save();
            foreach (['Why four Gospels?', 'The world of the first century'] as $i => $t) {
                $l = new Lesson(['title' => $t, 'type' => 'text', 'content' => '<p>Demonstration lesson text.</p>', 'duration_seconds' => 600, 'is_preview' => $i === 0]);
                $l->course_id = $c->id;
                $l->module_id = $m->id;
                $l->position = $i + 1;
                $l->save();
            }
        }
    }
}
