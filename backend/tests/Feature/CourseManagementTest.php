<?php

use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Services\CourseWorkflow;

function asStaff($test, $user)
{
    $test->actingAs($user);
    $test->withSession(['mfa_verified' => true]);

    return $user;
}

function newCourseBody(array $over = []): array
{
    return array_merge(['title' => 'Pastoral Care', 'summary' => 'Care for the flock.', 'description' => '<p>Hello</p>', 'objectives' => ['Learn to care'], 'level' => 'beginner'], $over);
}

it('lets a lecturer create a course as lead instructor and strips dangerous HTML', function () {
    $lect = asStaff($this, $this->makeUser('lecturer'));
    $res = $this->postJson('/api/v1/manage/courses', newCourseBody(['description' => '<p>Safe</p><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">x</a>']))
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.instructors.0.id', $lect->id);
    expect($res->json('data.description'))->toContain('Safe')->not->toContain('script')->not->toContain('onerror')->not->toContain('javascript:');
    expect(Course::firstOrFail()->slug)->toBe('pastoral-care');
});

it('keeps lecturers out of each other\'s courses', function () {
    $owner = $this->makeUser('lecturer');
    $other = $this->makeUser('lecturer');
    $c = Course::factory()->withCurriculum(1, $owner)->create();
    $m = $c->modules()->first();
    $l = $m->lessons()->first();

    asStaff($this, $other);
    $this->getJson('/api/v1/manage/courses')->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/manage/courses/{$c->id}")->assertForbidden();
    $this->putJson("/api/v1/manage/courses/{$c->id}", ['title' => 'Hijacked'])->assertForbidden();
    $this->postJson("/api/v1/manage/courses/{$c->id}/modules", ['title' => 'x'])->assertForbidden();
    $this->putJson("/api/v1/manage/lessons/{$l->id}", ['title' => 'x'])->assertForbidden();
    $this->postJson("/api/v1/manage/courses/{$c->id}/submit")->assertForbidden();
    $this->deleteJson("/api/v1/manage/courses/{$c->id}")->assertForbidden();
    expect($c->fresh()->title)->not->toBe('Hijacked');
});

it('denies the management area to students', function () {
    asStaff($this, $this->makeUser('student'));
    $this->getJson('/api/v1/manage/courses')->assertForbidden();
    $this->postJson('/api/v1/manage/courses', newCourseBody())->assertForbidden();
});

it('runs the full approval and publication workflow with the right permissions', function () {
    $lect = $this->makeUser('lecturer');
    $admin = $this->makeUser('admin');
    $c = Course::factory()->withCurriculum(1, $lect)->create();

    asStaff($this, $lect);
    $this->postJson("/api/v1/manage/courses/{$c->id}/approve")->assertForbidden();  // lecturers cannot self-approve
    $this->postJson("/api/v1/manage/courses/{$c->id}/publish")->assertForbidden();
    $this->postJson("/api/v1/manage/courses/{$c->id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
    $this->putJson("/api/v1/manage/courses/{$c->id}", ['title' => 'Edit during review'])->assertForbidden(); // locked while under review

    asStaff($this, $admin);
    $this->postJson("/api/v1/manage/courses/{$c->id}/publish")->assertStatus(409);        // cannot skip approval
    $this->postJson("/api/v1/manage/courses/{$c->id}/reject", ['notes' => 'Needs objectives'])->assertOk()->assertJsonPath('data.status', 'draft');
    expect($c->fresh()->review_notes)->toBe('Needs objectives');

    asStaff($this, $lect);
    $this->postJson("/api/v1/manage/courses/{$c->id}/submit")->assertOk();
    asStaff($this, $admin);
    $this->postJson("/api/v1/manage/courses/{$c->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/manage/courses/{$c->id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->getJson('/api/v1/public/courses/'.$c->slug)->assertOk();
    $this->postJson("/api/v1/manage/courses/{$c->id}/archive")->assertOk();
    $this->getJson('/api/v1/public/courses/'.$c->slug)->assertNotFound();

    expect(AuditLog::where('subject_id', $c->id)->pluck('action')->all())->toContain('course.submitted', 'course.rejected', 'course.approved', 'course.published', 'course.archived');
    expect($c->fresh()->versions()->count())->toBeGreaterThanOrEqual(2);
});

it('refuses to submit an incomplete course and explains why', function () {
    $lect = asStaff($this, $this->makeUser('lecturer'));
    $c = Course::factory()->create(['summary' => null, 'objectives' => []]);
    $c->instructors()->attach($lect->id, ['role' => 'lead']);
    $this->postJson("/api/v1/manage/courses/{$c->id}/submit")->assertUnprocessable()->assertJsonPath('code', 'course_not_ready')
        ->assertJsonFragment(['Add a short summary.'])->assertJsonFragment(['Add at least one lesson.']);
});

it('publishes scheduled courses when due, exactly once', function () {
    $this->seedRoles();
    $c = Course::factory()->withCurriculum()->create(['status' => 'approved']);
    $c->forceFill(['scheduled_publish_at' => now()->subMinute()])->save();
    $future = Course::factory()->create(['status' => 'approved']);
    $future->forceFill(['scheduled_publish_at' => now()->addDay()])->save();

    expect(app(CourseWorkflow::class)->publishDue())->toBe(1)->and(app(CourseWorkflow::class)->publishDue())->toBe(0);
    expect($c->fresh()->status)->toBe('published')->and($future->fresh()->status)->toBe('approved');
});

it('restricts pricing changes to administrators and validates money', function () {
    $lect = $this->makeUser('lecturer');
    $admin = $this->makeUser('admin');
    $c = Course::factory()->withCurriculum(1, $lect)->create();

    asStaff($this, $lect);
    $this->putJson("/api/v1/manage/courses/{$c->id}/pricing", ['price_minor' => 1])->assertForbidden();
    asStaff($this, $admin);
    $this->putJson("/api/v1/manage/courses/{$c->id}/pricing", ['price_minor' => -5])->assertUnprocessable();
    $this->putJson("/api/v1/manage/courses/{$c->id}/pricing", ['price_minor' => 1000, 'discount_price_minor' => 2000])->assertUnprocessable();
    $this->putJson("/api/v1/manage/courses/{$c->id}/pricing", ['price_minor' => 1000, 'currency' => 'USD'])->assertUnprocessable();
    $this->putJson("/api/v1/manage/courses/{$c->id}/pricing", ['price_minor' => 2500000])->assertOk()->assertJsonPath('data.price.amount_minor', 2500000);
    expect(AuditLog::where('action', 'course.pricing_changed')->exists())->toBeTrue();
});

it('validates YouTube links and extracts the id; rejects lookalike hosts', function () {
    $lect = asStaff($this, $this->makeUser('lecturer'));
    $c = Course::factory()->withCurriculum(1, $lect)->create();
    $m = $c->modules()->first();
    $ok = ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ?t=5', 'https://www.youtube.com/embed/dQw4w9WgXcQ', 'https://youtube.com/shorts/dQw4w9WgXcQ'];
    foreach ($ok as $url) {
        $this->postJson("/api/v1/manage/modules/{$m->id}/lessons", ['title' => 'V', 'type' => 'video', 'youtube_url' => $url])
            ->assertCreated()->assertJsonPath('data.youtube_id', 'dQw4w9WgXcQ')->assertJsonPath('data.video_source', 'youtube');
    }
    foreach (['https://evil.com/watch?v=dQw4w9WgXcQ', 'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', 'https://www.youtube.com/watch?v=short', 'https://user@youtube.com/watch?v=dQw4w9WgXcQ'] as $bad) {
        $this->postJson("/api/v1/manage/modules/{$m->id}/lessons", ['title' => 'V', 'type' => 'video', 'youtube_url' => $bad])->assertUnprocessable();
    }
});

it('appends lessons with contiguous positions and reorders atomically', function () {
    $lect = asStaff($this, $this->makeUser('lecturer'));
    $c = Course::factory()->withCurriculum(3, $lect)->create();
    $m = $c->modules()->first();
    $ids = $m->lessons()->pluck('id')->all();
    $this->putJson("/api/v1/manage/modules/{$m->id}/lessons/order", ['ids' => array_reverse($ids)])->assertOk();
    expect($m->lessons()->pluck('id')->all())->toBe(array_reverse($ids));
    $this->putJson("/api/v1/manage/modules/{$m->id}/lessons/order", ['ids' => [$ids[0]]])->assertUnprocessable()->assertJsonPath('code', 'invalid_order');
    $new = $this->postJson("/api/v1/manage/modules/{$m->id}/lessons", ['title' => 'Fourth', 'type' => 'text'])->assertCreated();
    expect($new->json('data.position'))->toBe(4);
});

it('blocks deleting content once students are enrolled', function () {
    $lect = asStaff($this, $this->makeUser('lecturer'));
    $student = $this->makeUser('student');
    $c = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $lesson = $c->lessons()->first();
    App\Models\CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $this->deleteJson("/api/v1/manage/lessons/{$lesson->id}")->assertStatus(409)->assertJsonPath('code', 'content_locked');
    expect(Lesson::find($lesson->id))->not->toBeNull();
});

it('prevents circular prerequisites', function () {
    $lect = asStaff($this, $this->makeUser('lecturer'));
    $a = Course::factory()->withCurriculum(1, $lect)->create();
    $b = Course::factory()->withCurriculum(1, $lect)->create();
    $this->putJson("/api/v1/manage/courses/{$a->id}/prerequisites", ['course_ids' => [$b->id]])->assertOk();
    $this->putJson("/api/v1/manage/courses/{$b->id}/prerequisites", ['course_ids' => [$a->id]])->assertUnprocessable()->assertJsonPath('code', 'circular_prerequisite');
    $this->putJson("/api/v1/manage/courses/{$a->id}/prerequisites", ['course_ids' => [$a->id]])->assertUnprocessable();
});

it('records a version snapshot on each edit', function () {
    $lect = asStaff($this, $this->makeUser('lecturer'));
    $c = Course::factory()->withCurriculum(1, $lect)->create();
    $this->putJson("/api/v1/manage/courses/{$c->id}", ['title' => 'Renamed'])->assertOk();
    $this->putJson("/api/v1/manage/courses/{$c->id}", ['summary' => 'New summary'])->assertOk();
    $v = $this->getJson("/api/v1/manage/courses/{$c->id}/versions")->assertOk();
    expect($v->json('data'))->toHaveCount(2)->and($v->json('data.1.snapshot.course.title'))->toBe('Renamed');
    expect($c->fresh()->version)->toBe(3);
});

it('manages categories with admin permission only and protects in-use categories', function () {
    asStaff($this, $this->makeUser('lecturer'));
    $this->postJson('/api/v1/admin/categories', ['name' => 'Nope'])->assertForbidden();
    asStaff($this, $this->makeUser('admin'));
    $cat = $this->postJson('/api/v1/admin/categories', ['name' => 'Church History'])->assertCreated()->assertJsonPath('data.slug', 'church-history');
    Course::factory()->create(['category_id' => $cat->json('data.id')]);
    $this->deleteJson('/api/v1/admin/categories/'.$cat->json('data.id'))->assertStatus(409);
});
