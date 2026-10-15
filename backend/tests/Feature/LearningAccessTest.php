<?php

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LessonResource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('enrols a verified student in a free course idempotently', function () {
    $student = $this->makeUser('student');
    $c = Course::factory()->published()->withCurriculum()->create();
    $this->actingAs($student);
    $this->postJson("/api/v1/learn/courses/{$c->id}/enrol")->assertCreated();
    $this->postJson("/api/v1/learn/courses/{$c->id}/enrol")->assertOk();
    expect(CourseEnrollment::where('user_id', $student->id)->count())->toBe(1);
});

it('does not grant a paid course through the free-enrolment endpoint', function () {
    $student = $this->makeUser('student');
    $c = Course::factory()->published()->priced(500000)->withCurriculum()->create();
    $this->actingAs($student)->postJson("/api/v1/learn/courses/{$c->id}/enrol")->assertStatus(402)->assertJsonPath('code', 'payment_required');
    expect(CourseEnrollment::count())->toBe(0);
});

it('requires a verified email, a published course and met prerequisites', function () {
    $unverified = $this->makeUser('student', ['email_verified_at' => null]);
    $c = Course::factory()->published()->withCurriculum()->create();
    $this->actingAs($unverified)->postJson("/api/v1/learn/courses/{$c->id}/enrol")->assertForbidden()->assertJsonPath('code', 'email_unverified');

    $student = $this->makeUser('student');
    $draft = Course::factory()->create();
    $this->actingAs($student)->postJson("/api/v1/learn/courses/{$draft->id}/enrol")->assertStatus(409);

    $pre = Course::factory()->published()->create();
    $adv = Course::factory()->published()->create();
    $adv->prerequisites()->attach($pre->id);
    $this->actingAs($student)->postJson("/api/v1/learn/courses/{$adv->id}/enrol")->assertUnprocessable()->assertJsonPath('code', 'prerequisites_unmet');
    CourseEnrollment::create(['course_id' => $pre->id, 'user_id' => $student->id, 'status' => 'completed', 'source' => 'free', 'enrolled_at' => now(), 'completed_at' => now()]);
    $this->actingAs($student)->postJson("/api/v1/learn/courses/{$adv->id}/enrol")->assertCreated();
});

it('gates lesson content by enrolment, preview flag, and staff status', function () {
    $lect = $this->makeUser('lecturer');
    $c = Course::factory()->published()->withCurriculum(2, $lect)->create(['sequential_progression' => false]);   // ordering is tested in VideoProgressTest
    [$l1, $l2] = $c->lessons()->orderBy('position')->get()->all();
    $l1->forceFill(['is_preview' => true])->save();
    $enrolled = $this->makeUser('student');
    $outsider = $this->makeUser('student');
    $otherLect = $this->makeUser('lecturer');
    CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $enrolled->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    $this->getJson("/api/v1/learn/lessons/{$l1->id}")->assertUnauthorized();
    $this->actingAs($outsider);
    $this->getJson("/api/v1/learn/lessons/{$l1->id}")->assertOk()->assertJsonPath('data.content', '<p>Body</p>'); // free preview
    $this->getJson("/api/v1/learn/lessons/{$l2->id}")->assertForbidden();
    $this->getJson("/api/v1/learn/courses/{$c->id}")->assertForbidden();
    $this->actingAs($enrolled);
    $this->getJson("/api/v1/learn/lessons/{$l2->id}")->assertOk();
    $this->getJson("/api/v1/learn/courses/{$c->id}")->assertOk();
    $this->actingAs($otherLect);
    $this->getJson("/api/v1/learn/lessons/{$l2->id}")->assertForbidden();   // lecturers only see courses they teach
    $this->actingAs($lect);
    $this->getJson("/api/v1/learn/lessons/{$l2->id}")->assertOk();
});

it('revokes lesson access when the enrolment is suspended or cancelled', function () {
    $c = Course::factory()->published()->withCurriculum()->create();
    $l = $c->lessons()->first();
    $s = $this->makeUser('student');
    $e = CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $s->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $this->actingAs($s)->getJson("/api/v1/learn/lessons/{$l->id}")->assertOk();
    $e->update(['status' => 'suspended']);
    $this->getJson("/api/v1/learn/lessons/{$l->id}")->assertForbidden();
});

it('does not reveal draft course lessons to enrolled-looking users', function () {
    $c = Course::factory()->withCurriculum()->create();   // draft
    $l = $c->lessons()->first();
    $s = $this->makeUser('student');
    CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $s->id, 'status' => 'active', 'source' => 'admin', 'enrolled_at' => now()]);
    $this->actingAs($s)->getJson("/api/v1/learn/lessons/{$l->id}")->assertForbidden();
});

it('returns 404 rather than a server error for malformed ids', function () {
    $this->actingAs($this->makeUser('student'));
    $this->getJson('/api/v1/learn/lessons/not-a-uuid')->assertNotFound();
});

it('stores documents privately and serves them only to authorised learners', function () {
    Storage::fake('private');
    $lect = $this->makeUser('lecturer');
    $c = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $l = $c->lessons()->first();
    $lect2 = $lect;

    $this->actingAs($lect2)->withSession(['mfa_verified' => true]);
    $res = $this->postJson("/api/v1/manage/lessons/{$l->id}/resources", ['title' => 'Notes', 'file' => UploadedFile::fake()->create('../../evil name.pdf', 200, 'application/pdf')])->assertCreated();
    $r = LessonResource::findOrFail($res->json('data.id'));
    Storage::disk('private')->assertExists($r->path);
    expect($r->path)->toStartWith("lesson-resources/{$c->id}/")->not->toContain('evil');

    $this->postJson("/api/v1/manage/lessons/{$l->id}/resources", ['title' => 'x', 'file' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php')])->assertUnprocessable();
    $this->postJson("/api/v1/manage/lessons/{$l->id}/resources", ['title' => 'x', 'file' => UploadedFile::fake()->create('big.pdf', 60000, 'application/pdf')])->assertUnprocessable();

    $outsider = $this->makeUser('student');
    $enrolled = $this->makeUser('student');
    CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $enrolled->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $this->actingAs($outsider)->get("/api/v1/learn/resources/{$r->id}/download")->assertForbidden();
    $this->actingAs($enrolled)->get("/api/v1/learn/resources/{$r->id}/download")->assertOk()->assertDownload();
});
