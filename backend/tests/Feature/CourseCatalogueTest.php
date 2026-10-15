<?php

use App\Models\Course;
use App\Models\Lesson;

it('lists only published courses and hides drafts, with real filters', function () {
    $lect = $this->makeUser('lecturer');
    Course::factory()->published()->withCurriculum(2, $lect)->create(['title' => 'Biblical Hermeneutics', 'level' => 'advanced', 'price_minor' => 500000]);
    Course::factory()->published()->withCurriculum(1, $lect)->create(['title' => 'Intro to Evangelism', 'level' => 'beginner', 'price_minor' => 0]);
    Course::factory()->withCurriculum(1, $lect)->create(['title' => 'Secret Draft']);

    $this->getJson('/api/v1/public/courses')->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonMissing(['title' => 'Secret Draft']);
    $this->getJson('/api/v1/public/courses?q=hermen')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Biblical Hermeneutics');
    $this->getJson('/api/v1/public/courses?price=free')->assertJsonCount(1, 'data')->assertJsonPath('data.0.price.is_free', true);
    $this->getJson('/api/v1/public/courses?level=advanced')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/public/courses?sort=price_desc')->assertJsonPath('data.0.title', 'Biblical Hermeneutics');
});

it('treats search input as data, not as SQL or wildcards', function () {
    Course::factory()->published()->create(['title' => 'Plain Course']);
    $this->getJson('/api/v1/public/courses?q='.urlencode("'; DROP TABLE courses;--"))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/public/courses?q=%25')->assertOk()->assertJsonCount(0, 'data'); // a literal % must not match everything
    expect(Course::count())->toBe(1);
});

it('shows full course detail but never lesson bodies or private notes', function () {
    $lect = $this->makeUser('lecturer', ['name' => 'Dr. Ada']);
    $c = Course::factory()->published()->withCurriculum(2, $lect)->create(['review_notes' => 'internal note']);
    $res = $this->getJson('/api/v1/public/courses/'.$c->slug)->assertOk()
        ->assertJsonPath('data.title', $c->title)->assertJsonPath('data.instructors.0.name', 'Dr. Ada')
        ->assertJsonPath('data.curriculum.0.lessons.0.title', 'Lesson 1')->assertJsonCount(2, 'data.curriculum.0.lessons');
    expect(json_encode($res->json()))->not->toContain('Body')->not->toContain('internal note');
});

it('returns 404 for unpublished or unknown courses', function () {
    $c = Course::factory()->withCurriculum()->create();
    $this->getJson('/api/v1/public/courses/'.$c->slug)->assertNotFound();
    $this->getJson('/api/v1/public/courses/nope')->assertNotFound();
});

it('applies a discount only inside its window', function () {
    $this->seedRoles();
    $c = Course::factory()->published()->create(['price_minor' => 1000000]);
    $c->forceFill(['discount_price_minor' => 700000, 'discount_starts_at' => now()->subDay(), 'discount_ends_at' => now()->addDay()])->save();
    $this->getJson('/api/v1/public/courses/'.$c->slug)->assertJsonPath('data.price.amount_minor', 700000)->assertJsonPath('data.price.discount_active', true);
    $c->forceFill(['discount_ends_at' => now()->subHour()])->save();
    $this->getJson('/api/v1/public/courses/'.$c->slug)->assertJsonPath('data.price.amount_minor', 1000000)->assertJsonPath('data.price.discount_active', false);
});

it('only exposes lecturers who opted in to a public profile', function () {
    $a = $this->makeUser('lecturer', ['name' => 'Public Lecturer']);
    $a->profile()->create(['public_profile' => true, 'headline' => 'Pastor']);
    $b = $this->makeUser('lecturer', ['name' => 'Private Lecturer']);
    $b->profile()->create(['public_profile' => false]);
    $this->getJson('/api/v1/public/lecturers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Public Lecturer');
    $this->getJson('/api/v1/public/lecturers/'.$b->id)->assertNotFound();
    expect(json_encode($this->getJson('/api/v1/public/lecturers')->json()))->not->toContain('@'); // no email disclosure
});
