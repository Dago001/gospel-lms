<?php

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function ask($t, string $message, $user = null)
{
    $t = $user ? $t->actingAs($user) : $t;

    return $t->postJson('/api/v1/chat/message', ['message' => $message]);
}

it('greets and offers starting points', function () {
    $this->getJson('/api/v1/chat/greeting')->assertOk()->assertJsonPath('data.intent', 'welcome')->assertJsonCount(5, 'data.suggestions');
    ask($this, 'hello')->assertOk()->assertJsonPath('data.intent', 'welcome');
});

it('answers common questions from the vetted topics', function (string $q, string $intent) {
    ask($this, $q)->assertOk()->assertJsonPath('data.intent', $intent);
})->with([
    ['How do I enrol in a course?', 'enrol'],
    ['I paid but I cannot access my course', 'paid_no_access'],
    ['what is your refund policy', 'refund'],
    ['How do I get a certificate?', 'certificate'],
    ['how do timed exams work', 'exams'],
    ['I forgot my password', 'password'],
    ['how do live classes on zoom work', 'live'],
    ['can I study on my phone', 'devices'],
    ['I want to talk to a human', 'human'],
]);

it('finds only published courses and never leaks drafts', function () {
    $lect = $this->makeUser('lecturer');
    Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Romans Explained', 'price_minor' => 0, 'slug' => 'romans-explained']);
    Course::factory()->withCurriculum(1, $lect)->create(['title' => 'Romans Secret Draft', 'slug' => 'romans-draft']);

    $res = ask($this, 'Do you have any courses on Romans?')->assertOk()->assertJsonPath('data.intent', 'search');
    expect($res->json('data.cards'))->toHaveCount(1)->and($res->json('data.cards.0.url'))->toBe('/courses/romans-explained');
    $res->assertJsonMissing(['title' => 'Romans Secret Draft']);
    ask($this, 'courses about zzzunknown')->assertJsonPath('data.intent', 'search_none');
    ask($this, 'show free courses')->assertJsonPath('data.intent', 'search');
});

it('treats SQL injection and wildcard payloads as inert text', function (string $payload) {
    $lect = $this->makeUser('lecturer');
    Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Plain Course']);
    $courses = Course::count();
    $users = \App\Models\User::count();

    $res = ask($this, $payload)->assertOk();
    expect($res->json('data.reply'))->toBeString();

    expect(Schema::hasTable('courses'))->toBeTrue()->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Course::count())->toBe($courses)->and(\App\Models\User::count())->toBe($users);
})->with([
    "'; DROP TABLE courses;--",
    "' OR 1=1 --",
    "courses about ' UNION SELECT email, password FROM users --",
    '1; DELETE FROM users WHERE 1=1',
    '"; SELECT pg_sleep(5); --',
    'courses on %',
    'courses on _',
    "courses on \\' OR \\'1\\'=\\'1",
    "robert'); DROP TABLE students;--",
]);

it('a wildcard-only search does not match every course', function () {
    $lect = $this->makeUser('lecturer');
    Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Plain Course']);
    ask($this, 'courses on %')->assertJsonPath('data.cards', []);
});

it('never echoes markup or script from the visitor', function () {
    $res = ask($this, '<script>alert(1)</script> courses about <img src=x onerror=alert(1)>')->assertOk();
    expect(json_encode($res->json('data')))->not->toContain('<script')->not->toContain('onerror=')->not->toContain('<img');
    $res2 = ask($this, 'courses on <b>bold</b>')->assertOk();
    expect($res2->json('data.reply'))->not->toContain('<');
});

it('only emits in-app link paths', function () {
    $lect = $this->makeUser('lecturer');
    Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Evangelism', 'slug' => 'evangelism']);
    foreach (['how do I enrol', 'courses on evangelism', 'what can I study', 'talk to a human', 'refund'] as $q) {
        $d = ask($this, $q)->json('data');
        foreach (array_merge(array_column($d['links'], 'url'), array_column($d['cards'], 'url')) as $u) {
            expect($u)->toStartWith('/')->not->toStartWith('//')->not->toContain('://');
        }
    }
});

it('validates and limits the message size', function () {
    $this->postJson('/api/v1/chat/message', [])->assertUnprocessable();
    $this->postJson('/api/v1/chat/message', ['message' => ['array']])->assertUnprocessable();
    $this->postJson('/api/v1/chat/message', ['message' => str_repeat('a', 301)])->assertUnprocessable();
    $this->postJson('/api/v1/chat/message', ['message' => "   \u{0000}  "])->assertUnprocessable();
    ask($this, "\u{200B}\u{0007}.\u{200B}")->assertOk()->assertJsonPath('data.intent', 'welcome');   // only control/format characters and punctuation
    ask($this, str_repeat('romans ', 40))->assertOk();   // 280 chars is accepted
});

it('lists only the signed-in user\'s own courses and nothing for guests', function () {
    $lect = $this->makeUser('lecturer');
    $mine = Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'My Own Course']);
    $theirs = Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Someone Elses Course']);
    $me = $this->makeUser('student');
    $other = $this->makeUser('student');
    CourseEnrollment::create(['user_id' => $me->id, 'course_id' => $mine->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    CourseEnrollment::create(['user_id' => $other->id, 'course_id' => $theirs->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    ask($this, 'show my courses')->assertJsonPath('data.intent', 'my_courses_guest')->assertJsonMissing(['title' => 'My Own Course']);
    $res = ask($this, 'show my courses', $me)->assertJsonPath('data.intent', 'my_courses');
    expect(array_column($res->json('data.cards'), 'title'))->toBe(['My Own Course']);
});

it('verifies certificates with minimal disclosure', function () {
    $lect = $this->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Verified Course']);
    $holder = $this->makeUser('student', ['name' => 'Ada Holder', 'email' => 'secret-ada@example.com']);
    $code = Str::lower(Str::random(32));
    Certificate::create(['number' => 'GL-TEST-1', 'verification_code' => $code, 'user_id' => $holder->id, 'course_id' => $course->id, 'status' => 'active', 'holder_name' => 'Ada Holder', 'course_title' => 'Verified Course', 'issued_at' => now()]);

    $res = ask($this, "please check $code")->assertOk()->assertJsonPath('data.intent', 'cert_valid');
    expect($res->json('data.reply'))->toContain('Ada Holder')->not->toContain('secret-ada@example.com');
    ask($this, str_repeat('0', 32))->assertJsonPath('data.intent', 'cert_missing');
    Certificate::query()->update(['status' => 'revoked']);
    ask($this, $code)->assertJsonPath('data.intent', 'cert_revoked');
});

it('rate limits abusive callers', function () {
    $last = null;
    for ($i = 0; $i < 17; $i++) {
        $last = ask($this, 'hello')->status();
    }
    expect($last)->toBe(429);
});

it('stores nothing about the conversation', function () {
    $tables = collect(\Illuminate\Support\Facades\DB::select("select table_name from information_schema.tables where table_schema='public'"))->pluck('table_name');
    expect($tables->filter(fn ($t) => str_contains($t, 'chat')))->toBeEmpty();
    $before = \Illuminate\Support\Facades\DB::table('audit_logs')->count();
    ask($this, 'How do I enrol?')->assertOk();
    expect(\Illuminate\Support\Facades\DB::table('audit_logs')->count())->toBe($before);
});
