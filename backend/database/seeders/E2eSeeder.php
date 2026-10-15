<?php

namespace Database\Seeders;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\TwoFactorAuthentication;
use App\Models\User;
use Illuminate\Database\Seeder;

/** End-to-end test fixtures. Local/test only; never runs in production. */
class E2eSeeder extends Seeder
{
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }
        $this->call(DemoSeeder::class);
        foreach (['admin@demo.test', 'finance@demo.test'] as $email) {
            TwoFactorAuthentication::updateOrCreate(['user_id' => User::where('email', $email)->value('id')], ['secret' => self::TOTP_SECRET, 'recovery_codes' => [], 'confirmed_at' => now()]);
        }
        $lect = User::where('email', 'lecturer@demo.test')->first();
        $free = Course::where('slug', 'demo-introduction-to-the-gospels')->first();
        $free->forceFill(['sequential_progression' => true])->save();

        $paid = Course::factory()->published()->withCurriculum(1, $lect)->priced(2500000)->create(['title' => 'E2E Paid Course', 'slug' => 'e2e-paid-course']);
        $paid->forceFill(['published_at' => now()])->save();

        $bank = QuestionBank::create(['course_id' => $free->id, 'title' => 'E2E bank']);
        $q1 = Question::create(['bank_id' => $bank->id, 'type' => 'mcq', 'prompt' => 'Who wrote the Gospel of Luke?', 'points' => 5]);
        foreach ([['Luke', true], ['Peter', false], ['Paul', false]] as $i => [$l, $ok]) {
            $q1->options()->create(['label' => $l, 'is_correct' => $ok, 'position' => $i + 1]);
        }
        $q2 = Question::create(['bank_id' => $bank->id, 'type' => 'short', 'prompt' => 'Name the fourth Gospel.', 'points' => 5, 'accepted_answers' => ['John', 'Gospel of John']]);
        $quiz = Assessment::factory()->create(['course_id' => $free->id, 'title' => 'E2E Quiz', 'time_limit_minutes' => 20, 'pass_mark_percent' => 50, 'review_policy' => 'immediate']);
        $quiz->fixedQuestions()->sync([$q1->id => ['position' => 1], $q2->id => ['position' => 2]]);
    }
}
