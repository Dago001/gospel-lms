<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SecurityNotice;
use Illuminate\Support\Facades\Notification;

function mfaSession($test, User $user): User
{
    $test->actingAs($user);
    $test->withSession(['mfa_verified' => true]);

    return $user;
}

it('denies every admin endpoint to students, lecturers and finance officers', function () {
    foreach (['student', 'lecturer', 'finance'] as $role) {
        $u = mfaSession($this, $this->makeUser($role));
        $target = $this->makeUser('student');
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'roles' => ['admin']])->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['admin']])->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
        expect($target->fresh()->roleSlugs())->toBe(['student']);
    }
});

it('rejects unauthenticated access to protected endpoints', function () {
    $this->getJson('/api/v1/admin/users')->assertUnauthorized();
    $this->getJson('/api/v1/account/sessions')->assertUnauthorized();
    $this->postJson('/api/v1/auth/two-factor/setup')->assertUnauthorized();
});

it('lets an administrator create a lecturer, who is verified and told to set a password', function () {
    Notification::fake();
    $admin = mfaSession($this, $this->makeUser('admin'));
    $res = $this->postJson('/api/v1/admin/users', ['name' => 'Rev. Dr. Test', 'email' => 'Lecturer@Example.com', 'roles' => ['lecturer']])
        ->assertCreated()->assertJsonPath('data.roles', ['lecturer'])->assertJsonPath('data.email_verified', true);
    $lecturer = User::findOrFail($res->json('data.id'));
    Notification::assertSentTo($lecturer, Illuminate\Auth\Notifications\ResetPassword::class);
    expect(AuditLog::where('action', 'user.created')->where('actor_id', $admin->id)->exists())->toBeTrue();
});

it('records role changes in the audit log with before and after values', function () {
    mfaSession($this, $this->makeUser('admin'));
    $target = $this->makeUser('student');
    $this->putJson("/api/v1/admin/users/{$target->id}/roles", ['roles' => ['student', 'lecturer']])->assertOk();
    $log = AuditLog::where('action', 'user.roles_changed')->firstOrFail();
    expect($log->old_values['roles'])->toBe(['student'])->and($log->new_values['roles'])->toEqualCanonicalizing(['student', 'lecturer']);
});

it('prevents removing the last administrator or suspending oneself', function () {
    $admin = mfaSession($this, $this->makeUser('admin'));
    $this->putJson("/api/v1/admin/users/{$admin->id}/roles", ['roles' => ['student']])->assertUnprocessable()->assertJsonPath('code', 'self_demotion');
    $this->postJson("/api/v1/admin/users/{$admin->id}/suspend", ['reason' => 'x'])->assertUnprocessable();
    $other = $this->makeUser('admin');
    $this->putJson("/api/v1/admin/users/{$other->id}/roles", ['roles' => ['student']])->assertOk(); // two admins: allowed
    $this->putJson("/api/v1/admin/users/{$admin->id}/roles", ['roles' => ['student']])->assertUnprocessable();
});

it('suspends and reactivates a user, revoking sessions and notifying them', function () {
    Notification::fake();
    mfaSession($this, $this->makeUser('admin'));
    $target = $this->makeUser('student');
    $this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'Policy breach'])->assertOk();
    expect($target->fresh())->status->toBe('suspended')->suspension_reason->toBe('Policy breach');
    Notification::assertSentTo($target, SecurityNotice::class);
    $this->postJson("/api/v1/admin/users/{$target->id}/reactivate")->assertOk();
    expect($target->fresh()->status)->toBe('active');
    expect(AuditLog::whereIn('action', ['user.suspended', 'user.reactivated'])->count())->toBe(2);
});

it('filters and paginates users', function () {
    mfaSession($this, $this->makeUser('admin'));
    User::factory()->count(30)->withRole('student')->create();
    $this->makeUser('lecturer', ['name' => 'Zed Lecturer']);
    $this->getJson('/api/v1/admin/users?per_page=10')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10);
    $this->getJson('/api/v1/admin/users?role=lecturer')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/users?q=zed')->assertOk()->assertJsonPath('data.0.name', 'Zed Lecturer');
});

it('keeps finance officers away from academic and security permissions', function () {
    $this->seedRoles();
    $finance = $this->makeUser('finance');
    expect($finance->hasPermission('finance.refund'))->toBeTrue()
        ->and($finance->hasPermission('grades.view-all'))->toBeFalse()
        ->and($finance->hasPermission('settings.manage'))->toBeFalse()
        ->and($finance->hasPermission('roles.manage'))->toBeFalse();
});

it('does not allow the fixed administrator role permissions to be edited', function () {
    mfaSession($this, $this->makeUser('admin'));
    $admin = App\Models\Role::where('slug', 'admin')->firstOrFail();
    $this->putJson("/api/v1/admin/roles/{$admin->id}/permissions", ['permissions' => []])->assertUnprocessable();
    $fin = App\Models\Role::where('slug', 'finance')->firstOrFail();
    $this->putJson("/api/v1/admin/roles/{$fin->id}/permissions", ['permissions' => ['finance.view']])->assertOk();
    expect($fin->permissions()->pluck('slug')->all())->toBe(['finance.view']);
});

it('makes audit logs append-only at the database level', function () {
    $log = app(App\Services\AuditLogger::class)->log('test.event');
    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(Illuminate\Database\QueryException::class);
    expect(fn () => AuditLog::where('id', $log->id)->delete())->toThrow(Illuminate\Database\QueryException::class);
});

it('redacts secrets from audit values', function () {
    $log = app(App\Services\AuditLogger::class)->log('test.redact', null, null, ['password' => 'hunter2', 'name' => 'ok']);
    expect($log->fresh()->new_values)->toEqual(['password' => '[redacted]', 'name' => 'ok']);
});

it('sends the expected security headers', function () {
    $this->getJson('/api/v1/auth/me')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
});

it('creates a verified administrator from the console command', function () {
    $this->artisan('gospel:create-admin', ['email' => 'boss@example.com', 'name' => 'Boss', '--password' => 'Str0ng-Passw0rd!'])->assertSuccessful();
    $u = User::where('email', 'boss@example.com')->firstOrFail();
    expect($u->hasVerifiedEmail())->toBeTrue()->and($u->hasRole('admin'))->toBeTrue();
    $this->artisan('gospel:create-admin', ['email' => 'weak@example.com', 'name' => 'Weak', '--password' => 'short'])->assertFailed();
});
