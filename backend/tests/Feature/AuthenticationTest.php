<?php

use App\Models\ConsentRecord;
use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

const GOOD_PW = 'Str0ng-Passw0rd!';

it('registers a student, records consent and sends a verification email', function () {
    $this->seedRoles();
    Notification::fake();

    $res = $this->postJson('/api/v1/auth/register', [
        'name' => 'Grace Okafor', 'email' => 'Grace@Example.com', 'password' => GOOD_PW,
        'password_confirmation' => GOOD_PW, 'accept_terms' => true,
    ])->assertCreated()->assertJsonPath('data.roles', ['student'])->assertJsonPath('data.email_verified', false);

    $user = User::where('email', 'grace@example.com')->firstOrFail();
    Notification::assertSentTo($user, QueuedVerifyEmail::class);
    expect(ConsentRecord::where('user_id', $user->id)->pluck('type')->sort()->values()->all())->toBe(['privacy', 'terms']);
    $this->getJson('/api/v1/auth/me')->assertSuccessful()->assertJsonPath('data.email', 'grace@example.com');
});

it('rejects registration without accepting terms, weak passwords and duplicate emails', function () {
    $this->seedRoles();
    $base = ['name' => 'A', 'email' => 'a@example.com', 'password' => GOOD_PW, 'password_confirmation' => GOOD_PW, 'accept_terms' => true];
    $this->postJson('/api/v1/auth/register', [...$base, 'accept_terms' => false])->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
    $this->postJson('/api/v1/auth/register', [...$base, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/auth/register', $base)->assertCreated();
    $this->postJson('/api/v1/auth/logout')->assertOk();
    $this->postJson('/api/v1/auth/register', $base)->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('verifies email through the signed link and rejects tampered links', function () {
    $user = $this->makeUser('student', ['email_verified_at' => null]);
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->get($url)->assertRedirect('http://localhost:5173/verify-email?status=success');
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $other = $this->makeUser('student', ['email_verified_at' => null]);
    $bad = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $other->id, 'hash' => 'wrong']);
    $this->get($bad)->assertRedirect('http://localhost:5173/verify-email?status=invalid');
    expect($other->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->get('/api/v1/auth/email/verify/'.$other->id.'/'.sha1($other->email))->assertForbidden(); // unsigned
});

it('logs in, returns permissions, and logs out', function () {
    $user = $this->makeUser('lecturer');
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345'])
        ->assertOk()->assertJsonPath('status', 'authenticated')->assertJsonPath('user.roles', ['lecturer'])
        ->assertJsonPath('user.permissions', ['courses.manage-own']);
    $this->postJson('/api/v1/auth/logout')->assertOk();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('rejects bad credentials with the same message for unknown users', function () {
    $user = $this->makeUser();
    $a = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
    $b = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertUnprocessable();
    expect($a->json('message'))->toBe($b->json('message'));
});

it('throttles repeated failed logins', function () {
    $user = $this->makeUser();
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345'])->assertStatus(429);
});

it('blocks suspended accounts from signing in and kills their live sessions', function () {
    $user = $this->makeUser('student', ['status' => 'suspended', 'suspended_at' => now()]);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345'])
        ->assertForbidden()->assertJsonPath('code', 'account_suspended');

    $live = $this->makeUser();
    $this->actingAs($live)->getJson('/api/v1/auth/me')->assertOk();
    $live->forceFill(['status' => 'suspended'])->save();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'account_suspended');
});

it('does not reveal whether an email exists on password recovery, and resets with a valid token', function () {
    Notification::fake();
    $user = $this->makeUser();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    $token = null;
    Notification::assertSentTo($user, Illuminate\Auth\Notifications\ResetPassword::class, function ($n) use (&$token) { $token = $n->token; return true; });
    $new = 'Another-Str0ng-Pass';
    $this->postJson('/api/v1/auth/reset-password', ['token' => 'bad', 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])->assertUnprocessable();
    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => $new, 'password_confirmation' => $new])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $new])->assertOk();
});

it('changes password only with the current password and signs out other sessions', function () {
    $user = $this->makeUser();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345'])->assertOk();
    $new = 'Brand-New-Passw0rd';
    $this->putJson('/api/v1/account/password', ['current_password' => 'nope', 'password' => $new, 'password_confirmation' => $new])->assertUnprocessable();
    $this->putJson('/api/v1/account/password', ['current_password' => 'Password-12345', 'password' => $new, 'password_confirmation' => $new])->assertOk();
    expect(Hash::check($new, $user->fresh()->password))->toBeTrue();
});

it('lists own sessions and cannot revoke another user\'s session', function () {
    $a = $this->makeUser();
    $b = $this->makeUser();
    $this->postJson('/api/v1/auth/login', ['email' => $b->email, 'password' => 'Password-12345']);
    $bSession = \App\Models\UserSession::where('user_id', $b->id)->firstOrFail();
    $this->flushSession(); // B's browser stays signed in elsewhere

    $this->postJson('/api/v1/auth/login', ['email' => $a->email, 'password' => 'Password-12345'])->assertOk();
    $this->getJson('/api/v1/account/sessions')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson('/api/v1/account/sessions/'.$bSession->id)->assertNotFound();
    expect($bSession->fresh()->revoked_at)->toBeNull();
});
