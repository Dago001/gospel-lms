<?php

use App\Services\TotpService;

function enrolTotp($test, $user): array
{
    $test->actingAs($user);
    $setup = $test->postJson('/api/v1/auth/two-factor/setup', ['password' => 'Password-12345'])->assertOk()->json();
    $totp = new TotpService;
    $code = $totp->codeAt($setup['secret'], $totp->currentStep());
    $res = $test->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])->assertOk();

    return [$setup['secret'], $res->json('recovery_codes')];
}

it('requires the account password to begin enrolment and rejects a wrong confirmation code', function () {
    $user = $this->makeUser('student');
    $this->actingAs($user);
    $this->postJson('/api/v1/auth/two-factor/setup', ['password' => 'wrong'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/two-factor/setup', ['password' => 'Password-12345'])->assertOk()->assertJsonStructure(['secret', 'otpauth_uri']);
    $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('forces two-factor sign-in once enabled, and issues a session only after a valid code', function () {
    $user = $this->makeUser('student');
    [$secret] = enrolTotp($this, $user);
    $this->postJson('/api/v1/auth/logout');
    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345'])
        ->assertOk()->assertJsonPath('status', 'two_factor_required');
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();   // password alone grants nothing
    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => '123456'])->assertUnprocessable();

    $totp = new TotpService;
    $code = $totp->codeAt($secret, $totp->currentStep() + 1); // fresh step, not the one used at enrolment
    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => $code])->assertOk()->assertJsonPath('status', 'authenticated');
    $this->getJson('/api/v1/auth/me')->assertSuccessful()->assertJsonPath('data.mfa_verified', true);
});

it('rejects a replayed TOTP code', function () {
    $user = $this->makeUser('student');
    [$secret] = enrolTotp($this, $user);
    $totp = new TotpService;
    $code = $totp->codeAt($secret, $totp->currentStep() + 1);

    $this->flushSession();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345']);
    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => $code])->assertOk();
    $this->flushSession();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345']);
    $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => $code])->assertUnprocessable();
});

it('accepts each recovery code exactly once', function () {
    $user = $this->makeUser('student');
    [, $codes] = enrolTotp($this, $user);
    expect($codes)->toHaveCount(8);

    foreach ([true, false] as $expectOk) {
        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password-12345']);
        $r = $this->postJson('/api/v1/auth/two-factor/challenge', ['code' => $codes[0]]);
        $expectOk ? $r->assertOk() : $r->assertUnprocessable();
    }
});

it('forces privileged roles to enrol before using protected routes, and forbids disabling', function () {
    $admin = $this->makeUser('admin');
    $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'Password-12345'])
        ->assertOk()->assertJsonPath('user.mfa_setup_required', true);
    $this->getJson('/api/v1/admin/users')->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
    $this->getJson('/api/v1/auth/me')->assertSuccessful(); // setup endpoints stay reachable

    enrolTotp($this, $admin);
    $this->getJson('/api/v1/admin/users')->assertOk();
    $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'Password-12345'])->assertForbidden()->assertJsonPath('code', 'mfa_mandatory');
});

it('requires a fresh MFA challenge for privileged users who already enrolled', function () {
    $finance = $this->makeUser('finance');
    [$secret] = enrolTotp($this, $finance);
    $this->flushSession();
    $this->app['auth']->forgetGuards();
    // Simulate a session where the password step passed but MFA did not: a new acting session without the flag.
    $this->actingAs($finance->fresh());
    $this->flushSession();
    $this->getJson('/api/v1/account/sessions')->assertForbidden()->assertJsonPath('code', 'mfa_required');
});
