<?php

use App\Models\Announcement;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\DiscussionPost;
use App\Models\DiscussionThread;
use App\Models\NotificationLog;
use App\Models\SupportTicket;
use App\Notifications\AnnouncementNotice;
use App\Notifications\ForumReplyNotice;
use App\Notifications\GradePublishedNotice;
use App\Notifications\PaymentConfirmed;
use App\Notifications\SupportReplyNotice;
use Illuminate\Support\Facades\Notification;

function commsCourse($test): array
{
    $lect = $test->makeUser('lecturer');
    $course = Course::factory()->published()->withCurriculum(1, $lect)->create();
    $student = $test->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);

    return [$lect, $student, $course];
}

// ---------------------------------------------------------------- notification centre
it('stores in-app notifications and lets users read only their own', function () {
    $a = $this->makeUser();
    $b = $this->makeUser();
    $a->notify(new GradePublishedNotice('Essay 1'));
    $b->notify(new GradePublishedNotice('Essay 2'));
    $this->actingAs($a);
    $list = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 1);
    expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.data.body'))->toBe('Essay 1');
    $bId = $b->notifications()->first()->id;
    $this->postJson("/api/v1/notifications/{$bId}/read")->assertNotFound();                  // another user's notification
    expect($b->unreadNotifications()->count())->toBe(1);
    $this->postJson('/api/v1/notifications/'.$a->notifications()->first()->id.'/read')->assertOk();
    $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread', 0);
    $a->notify(new GradePublishedNotice('Essay 3'));
    $this->postJson('/api/v1/notifications/read-all')->assertOk();
    $this->getJson('/api/v1/notifications?unread=1')->assertJsonCount(0, 'data');
});

it('honours channel preferences but never suppresses payment or security notices', function () {
    $u = $this->makeUser();
    $this->actingAs($u)->putJson('/api/v1/notifications/preferences', ['preferences' => [['type' => 'grades', 'email' => false, 'in_app' => false]]])->assertOk();
    $u->notify(new GradePublishedNotice('Muted'));
    expect($u->notifications()->count())->toBe(0);
    expect(NotificationLog::where(['user_id' => $u->id, 'status' => 'suppressed'])->count())->toBe(2);   // mail + database both suppressed and recorded

    $u->notify(new PaymentConfirmed('GL-1', 100000, 'NGN', 'RCT-1'));
    expect($u->notifications()->where('data->type', 'payment.confirmed')->count())->toBe(1);

    $this->putJson('/api/v1/notifications/preferences', ['preferences' => [['type' => 'grades', 'email' => false, 'in_app' => true]]]);
    $u->notify(new GradePublishedNotice('In-app only'));
    expect($u->notifications()->where('data->body', 'In-app only')->count())->toBe(1);
    $this->putJson('/api/v1/notifications/preferences', ['preferences' => [['type' => 'security', 'email' => false, 'in_app' => false]]])->assertUnprocessable();   // not configurable
    $this->getJson('/api/v1/notifications/preferences')->assertOk()->assertJsonFragment(['type' => 'grades', 'email' => false, 'in_app' => true]);
});

it('records delivery outcomes for administrators', function () {
    $u = $this->makeUser();
    $u->notify(new GradePublishedNotice('Logged'));
    expect(NotificationLog::where(['user_id' => $u->id, 'status' => 'sent'])->count())->toBe(2);
    $this->actingAs($this->makeUser('admin'))->withSession(['mfa_verified' => true]);
    $this->getJson('/api/v1/admin/notifications/log?status=sent')->assertOk()->assertJsonFragment(['notification' => 'GradePublishedNotice']);
    $this->getJson('/api/v1/admin/notifications/summary')->assertOk()->assertJsonPath('data.last_7_days.sent', 2);
    $this->actingAs($this->makeUser('student'))->getJson('/api/v1/admin/notifications/log')->assertForbidden();
});

// ---------------------------------------------------------------- announcements
it('lets a lecturer announce to their own course, notifying only enrolled learners, and sanitises content', function () {
    Notification::fake();
    [$lect, $student, $course] = commsCourse($this);
    $outsider = $this->makeUser();
    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $this->postJson("/api/v1/manage/courses/{$course->id}/announcements", ['title' => 'Exam moved', 'body' => '<p>Now Friday</p><script>alert(1)</script>', 'notify' => true])->assertCreated();
    expect(Announcement::first()->body)->not->toContain('script');
    Notification::assertSentTo($student, AnnouncementNotice::class);
    Notification::assertNotSentTo($outsider, AnnouncementNotice::class);
    $this->actingAs($student)->getJson("/api/v1/learn/courses/{$course->id}/announcements")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($outsider)->getJson("/api/v1/learn/courses/{$course->id}/announcements")->assertForbidden();
});

it('does not let other lecturers or students post course announcements, and notifies once', function () {
    [$lect, $student, $course] = commsCourse($this);
    foreach (['student', 'lecturer'] as $role) {
        $this->actingAs($this->makeUser($role))->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/courses/{$course->id}/announcements", ['title' => 'x', 'body' => 'y'])->assertForbidden();
    }
    Notification::fake();
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->postJson("/api/v1/manage/courses/{$course->id}/announcements", ['title' => 'Once', 'body' => 'b', 'notify' => true])->assertCreated();
    $id = Announcement::first()->id;
    App\Jobs\SendAnnouncement::dispatchSync($id);                       // a retried job must not notify twice
    Notification::assertSentToTimes($student, AnnouncementNotice::class, 1);
});

it('scopes platform announcements by audience and restricts posting to administrators', function () {
    $this->actingAs($this->makeUser('admin'))->withSession(['mfa_verified' => true]);
    $this->postJson('/api/v1/admin/announcements', ['title' => 'For lecturers', 'body' => 'b', 'audience' => 'lecturers'])->assertCreated();
    $this->postJson('/api/v1/admin/announcements', ['title' => 'For everyone', 'body' => 'b'])->assertCreated();
    $this->actingAs($this->makeUser('student'))->getJson('/api/v1/announcements')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'For everyone');
    $this->actingAs($this->makeUser('lecturer'))->getJson('/api/v1/announcements')->assertJsonCount(2, 'data');
    $this->actingAs($this->makeUser('lecturer'))->withSession(['mfa_verified' => true])->postJson('/api/v1/admin/announcements', ['title' => 'x', 'body' => 'y'])->assertForbidden();
});

it('hides expired and not-yet-published announcements', function () {
    [$lect, $student, $course] = commsCourse($this);
    $mk = fn ($t, $pub, $exp = null) => Announcement::create(['course_id' => $course->id, 'title' => $t, 'body' => 'b', 'publish_at' => $pub, 'expires_at' => $exp]);
    $mk('live', now()->subDay());
    $mk('future', now()->addDay());
    $mk('expired', now()->subDays(3), now()->subDay());
    $this->actingAs($student)->getJson("/api/v1/learn/courses/{$course->id}/announcements")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'live');
});

// ---------------------------------------------------------------- forum
it('lets enrolled learners discuss, notifies the thread author of replies, and keeps outsiders out', function () {
    Notification::fake();
    [$lect, $alice, $course] = commsCourse($this);
    $bob = $this->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $bob->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $outsider = $this->makeUser();
    $thread = $this->actingAs($alice)->postJson("/api/v1/learn/courses/{$course->id}/threads", ['title' => 'Question on Romans 8', 'body' => '<p>Help</p><img src=x onerror=alert(1)>'])->assertCreated()->json('data.id');
    expect(DiscussionThread::first()->body)->not->toContain('onerror');
    $this->actingAs($bob)->postJson("/api/v1/learn/threads/{$thread}/posts", ['body' => 'My view'])->assertCreated();
    Notification::assertSentTo($alice, ForumReplyNotice::class);
    $this->actingAs($alice)->postJson("/api/v1/learn/threads/{$thread}/posts", ['body' => 'Self reply'])->assertCreated();
    Notification::assertSentToTimes($alice, ForumReplyNotice::class, 1);                    // no self-notification
    $this->getJson("/api/v1/learn/threads/{$thread}")->assertOk()->assertJsonCount(2, 'data.posts');
    $this->actingAs($outsider);
    $this->getJson("/api/v1/learn/courses/{$course->id}/threads")->assertForbidden();
    $this->getJson("/api/v1/learn/threads/{$thread}")->assertForbidden();
    $this->postJson("/api/v1/learn/threads/{$thread}/posts", ['body' => 'let me in'])->assertForbidden();
});

it('limits editing and deleting to authors (short window) and moderators', function () {
    [$lect, $alice, $course] = commsCourse($this);
    $bob = $this->makeUser();
    CourseEnrollment::create(['course_id' => $course->id, 'user_id' => $bob->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
    $t = DiscussionThread::create(['course_id' => $course->id, 'user_id' => $alice->id, 'title' => 'T', 'body' => 'b', 'last_post_at' => now()]);
    $post = DiscussionPost::create(['thread_id' => $t->id, 'user_id' => $bob->id, 'body' => 'mine']);

    $this->actingAs($alice)->putJson("/api/v1/learn/posts/{$post->id}", ['body' => 'hijack'])->assertForbidden();
    $this->deleteJson("/api/v1/learn/posts/{$post->id}")->assertForbidden();
    $this->actingAs($bob)->putJson("/api/v1/learn/posts/{$post->id}", ['body' => 'edited'])->assertOk();
    $post->forceFill(['created_at' => now()->subHour()])->save();
    $this->putJson("/api/v1/learn/posts/{$post->id}", ['body' => 'too late'])->assertForbidden()->assertJsonPath('code', 'edit_not_allowed');
    expect($post->fresh()->body)->toBe('edited');

    $this->actingAs($lect)->withSession(['mfa_verified' => true]);
    $this->putJson("/api/v1/learn/threads/{$t->id}", ['locked' => true, 'pinned' => true])->assertOk();
    $this->actingAs($alice)->putJson("/api/v1/learn/threads/{$t->id}", ['locked' => false])->assertForbidden();             // students cannot moderate
    $this->postJson("/api/v1/learn/threads/{$t->id}/posts", ['body' => 'locked out'])->assertForbidden()->assertJsonPath('code', 'thread_locked');
    $this->actingAs($lect)->withSession(['mfa_verified' => true])->deleteJson("/api/v1/learn/posts/{$post->id}")->assertOk();
    expect(App\Models\AuditLog::where('action', 'forum.post_removed')->exists())->toBeTrue();
    $this->actingAs($this->makeUser('lecturer'))->withSession(['mfa_verified' => true])->putJson("/api/v1/learn/threads/{$t->id}", ['locked' => false])->assertForbidden();   // another course's lecturer
});

// ---------------------------------------------------------------- support
it('lets a signed-in user open, follow and close their own tickets, and never see internal notes or others\' tickets', function () {
    Notification::fake();
    $alice = $this->makeUser();
    $bob = $this->makeUser();
    $id = $this->actingAs($alice)->postJson('/api/v1/support/tickets', ['subject' => 'Cannot pay', 'message' => 'Card keeps failing', 'category' => 'payment'])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
    $this->actingAs($bob)->getJson("/api/v1/support/tickets/{$id}")->assertNotFound();
    $this->postJson("/api/v1/support/tickets/{$id}/messages", ['message' => 'intrude'])->assertNotFound();
    $this->postJson("/api/v1/support/tickets/{$id}/close")->assertNotFound();
    $this->getJson('/api/v1/support/tickets')->assertJsonCount(0, 'data');

    $staff = $this->makeUser('admin');
    $this->actingAs($staff)->withSession(['mfa_verified' => true]);
    $this->postJson("/api/v1/admin/support/tickets/{$id}/messages", ['message' => 'Check with bank first', 'internal' => true])->assertCreated();
    $this->postJson("/api/v1/admin/support/tickets/{$id}/messages", ['message' => 'Please try another card.'])->assertCreated();
    Notification::assertSentToTimes($alice, SupportReplyNotice::class, 1);                 // internal notes notify nobody
    $this->actingAs($alice);
    $view = $this->getJson("/api/v1/support/tickets/{$id}")->assertOk();
    expect(json_encode($view->json()))->not->toContain('Check with bank')->and($view->json('data.messages'))->toHaveCount(2)->and($view->json('data.messages.1.author'))->toBe('Support team');
    expect(SupportTicket::first()->status)->toBe('pending');
    $this->postJson("/api/v1/support/tickets/{$id}/messages", ['message' => 'Worked, thanks'])->assertCreated();
    expect(SupportTicket::first()->status)->toBe('open');
    $this->postJson("/api/v1/support/tickets/{$id}/close")->assertOk();
    $this->postJson("/api/v1/support/tickets/{$id}/messages", ['message' => 'one more'])->assertStatus(409);
});

it('accepts public contact messages with validation, a honeypot and rate limiting', function () {
    Notification::fake();
    $ok = ['name' => 'Visitor', 'email' => 'visitor@example.com', 'subject' => 'Admissions', 'message' => 'How do I enrol in the pastoral course?'];
    $this->postJson('/api/v1/public/contact', [...$ok, 'email' => 'nope'])->assertUnprocessable();
    $this->postJson('/api/v1/public/contact', [...$ok, 'message' => 'short'])->assertUnprocessable();
    $this->postJson('/api/v1/public/contact', [...$ok, 'website' => 'http://spam.example'])->assertUnprocessable();          // bots fill the honeypot
    $res = $this->postJson('/api/v1/public/contact', $ok)->assertCreated();
    expect(SupportTicket::first())->guest_email->toBe('visitor@example.com')->user_id->toBeNull();
    Notification::assertSentOnDemand(SupportReplyNotice::class);
    expect($res->json('data.number'))->toStartWith('SUP-');
    $this->postJson('/api/v1/public/contact', $ok)->assertCreated();       // 5th request in the window (the 3 rejected ones count too)
    $this->postJson('/api/v1/public/contact', $ok)->assertStatus(429);
});

it('restricts the support desk to staff with the permission and supports triage', function () {
    $alice = $this->makeUser();
    $t = SupportTicket::create(['number' => 'SUP-000001', 'user_id' => $alice->id, 'subject' => 'Help', 'category' => 'other', 'status' => 'open', 'priority' => 'normal', 'last_activity_at' => now()]);
    foreach (['student', 'lecturer', 'finance'] as $role) {
        $this->actingAs($this->makeUser($role))->withSession(['mfa_verified' => true]);
        $this->getJson('/api/v1/admin/support/tickets')->assertForbidden();
        $this->putJson("/api/v1/admin/support/tickets/{$t->id}", ['status' => 'closed'])->assertForbidden();
    }
    $staff = $this->makeUser('admin');
    $this->actingAs($staff)->withSession(['mfa_verified' => true]);
    $this->putJson("/api/v1/admin/support/tickets/{$t->id}", ['priority' => 'high', 'assigned_to' => $staff->id])->assertOk()->assertJsonPath('data.priority', 'high');
    $this->putJson("/api/v1/admin/support/tickets/{$t->id}", ['assigned_to' => $alice->id])->assertUnprocessable()->assertJsonPath('code', 'invalid_assignee');
    $this->getJson('/api/v1/admin/support/tickets?assigned=me')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/support/tickets?q=SUP-0000')->assertJsonCount(1, 'data');
    $this->putJson("/api/v1/admin/support/tickets/{$t->id}", ['status' => 'closed'])->assertOk();
    expect(SupportTicket::first()->closed_at)->not->toBeNull();
});
