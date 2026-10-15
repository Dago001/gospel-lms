<?php

use App\Models\AuditLog;
use App\Models\BackupAuditRecord;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\DataExportRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\BackupService;
use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function adminSession($test)
{
    $u = $test->makeUser('admin');
    $test->actingAs($u)->withSession(['mfa_verified' => true]);

    return $u;
}

it('validates and audits system settings, and applies them to documents', function () {
    adminSession($this);
    $this->putJson('/api/v1/admin/settings', ['settings' => ['academy.support_email' => 'not-an-email']])->assertUnprocessable();
    $this->putJson('/api/v1/admin/settings', ['settings' => ['unknown.key' => 'x']])->assertUnprocessable();
    $this->putJson('/api/v1/admin/settings', ['settings' => ['academy.name' => 'Grace Theological Academy', 'academy.signatory_name' => 'Rev. A. Okoro', 'academy.signatory_title' => 'Principal']])->assertOk();
    expect(Settings::academy()['name'])->toBe('Grace Theological Academy');
    expect(AuditLog::where('action', 'settings.updated')->count())->toBe(1);

    $cert = App\Models\Certificate::create(['number' => 'GLC-T', 'verification_code' => str_repeat('d', 32), 'user_id' => $this->makeUser()->id, 'course_id' => Course::factory()->create()->id, 'holder_name' => 'A B', 'course_title' => 'T', 'issued_at' => now(),
        'signatory_name' => Settings::academy()['signatory_name'], 'signatory_title' => Settings::academy()['signatory_title']]);
    expect($cert->signatory_name)->toBe('Rev. A. Okoro');
    $this->getJson("/api/v1/public/certificates/verify/{$cert->verification_code}")->assertJsonPath('data.issuer', 'Grace Theological Academy');

    foreach (['student', 'lecturer', 'finance'] as $role) {
        $this->actingAs($this->makeUser($role))->withSession(['mfa_verified' => true])->getJson('/api/v1/admin/settings')->assertForbidden();   // finance has no access to system settings
    }
});

it('reports integration status without ever revealing credentials', function () {
    config(['services.paystack.secret_key' => 'sk_test_SUPERSECRETVALUE', 'services.paystack.public_key' => 'pk_test_x', 'services.paystack.mode' => 'live']);
    adminSession($this);
    $res = $this->getJson('/api/v1/admin/integrations')->assertOk();
    $paystack = collect($res->json('data'))->firstWhere('name', 'Paystack');
    expect($paystack['configured'])->toBeTrue()->and($paystack['warning'])->toContain('does not match');
    expect(json_encode($res->json()))->not->toContain('SUPERSECRETVALUE');
});

it('reports system health and flags problems', function () {
    adminSession($this);
    Storage::fake('private');
    $r = $this->getJson('/api/v1/admin/health')->assertOk();
    expect($r->json('data.checks.database.ok'))->toBeTrue()->and($r->json('data.checks.storage.ok'))->toBeTrue()->and($r->json('data.checks.backups.ok'))->toBeFalse()->and($r->json('data.status'))->toBe('degraded');
    Cache::put('scheduler.heartbeat', now()->timestamp, 600);
    BackupAuditRecord::create(['type' => 'backup', 'status' => 'ok', 'started_at' => now()->subHour()]);
    $r = $this->getJson('/api/v1/admin/health')->assertOk();
    expect($r->json('data.checks.scheduler.ok'))->toBeTrue()->and($r->json('data.checks.backups.ok'))->toBeTrue();
});

it('lets authorised staff search the audit log and exports it', function () {
    $admin = adminSession($this);
    app(App\Services\AuditLogger::class)->log('demo.alpha', null, reason: 'first');
    app(App\Services\AuditLogger::class)->log('demo.beta', null, reason: '=cmd|calc');
    $this->getJson('/api/v1/admin/audit-logs?action=demo.')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/admin/audit-logs?action=demo.alpha')->assertJsonCount(1, 'data')->assertJsonPath('data.0.actor_id', $admin->id);
    expect($this->get('/api/v1/admin/audit-logs?format=csv&action=demo.')->streamedContent())->toContain("'=cmd|calc");
    $this->actingAs($this->makeUser('finance'))->withSession(['mfa_verified' => true])->getJson('/api/v1/admin/audit-logs')->assertForbidden();
});

// ---------------------------------------------------------------- privacy
it('exports only the requesting user\'s own data, once a day, downloadable only by them', function () {
    Storage::fake('private');
    $alice = $this->makeUser('student', ['name' => 'Alice Example']);
    $bob = $this->makeUser('student', ['name' => 'Bob Secret']);
    $c = Course::factory()->published()->create(['title' => 'Shared Course']);
    foreach ([$alice, $bob] as $u) {
        CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $u->id, 'status' => 'active', 'source' => 'free', 'enrolled_at' => now()]);
        $u->profile()->create(['phone' => $u === $alice ? '0800ALICE' : '0800BOB']);
    }
    $this->actingAs($alice)->postJson('/api/v1/account/data-exports')->assertStatus(202);
    $export = DataExportRequest::firstOrFail();
    expect($export->status)->toBe('ready');
    $body = Storage::disk('private')->get($export->file_path);
    expect($body)->toContain('Alice Example')->toContain('0800ALICE')->toContain('Shared Course')->not->toContain('Bob Secret')->not->toContain('0800BOB')->not->toContain('password');
    $this->postJson('/api/v1/account/data-exports')->assertStatus(429);
    $this->get("/api/v1/account/data-exports/{$export->id}/download")->assertOk()->assertDownload();
    $this->actingAs($bob)->get("/api/v1/account/data-exports/{$export->id}/download", ['Accept' => 'application/json'])->assertNotFound();
    $export->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($alice)->get("/api/v1/account/data-exports/{$export->id}/download", ['Accept' => 'application/json'])->assertNotFound();
});

it('anonymises a user irreversibly while keeping academic and financial records intact', function () {
    Storage::fake('private');
    $admin = adminSession($this);
    $u = $this->makeUser('student', ['name' => 'Erase Me', 'email' => 'erase@example.com']);
    $u->profile()->create(['phone' => '0803', 'bio' => 'private']);
    $c = Course::factory()->published()->create();
    CourseEnrollment::create(['course_id' => $c->id, 'user_id' => $u->id, 'status' => 'completed', 'source' => 'free', 'enrolled_at' => now()]);
    $url = "/api/v1/admin/users/{$u->id}/anonymise";
    $this->postJson($url, ['reason' => 'Erasure request #12', 'confirm_email' => 'wrong@example.com'])->assertUnprocessable()->assertJsonPath('code', 'confirmation_mismatch');
    $this->postJson($url, ['reason' => 'Erasure request #12', 'confirm_email' => 'erase@example.com'])->assertOk();
    $u->refresh();
    expect($u->name)->toBe('Former user')->and($u->email)->toStartWith('anonymised+')->and($u->status)->toBe('anonymised')->and($u->profile)->toBeNull()->and(CourseEnrollment::where('user_id', $u->id)->count())->toBe(1);
    expect(AuditLog::where('action', 'user.anonymised')->firstOrFail()->reason)->toBe('Erasure request #12');
    $this->postJson('/api/v1/auth/logout');
    $this->postJson('/api/v1/auth/login', ['email' => 'erase@example.com', 'password' => 'Password-12345'])->assertUnprocessable();
    $this->actingAs($admin)->withSession(['mfa_verified' => true])->postJson("/api/v1/admin/users/{$admin->id}/anonymise", ['reason' => 'self erase', 'confirm_email' => $admin->email])->assertUnprocessable();
    $busy = $this->makeUser('student', ['email' => 'busy@example.com']);
    Order::create(['number' => 'GL-B', 'user_id' => $busy->id, 'status' => 'pending', 'currency' => 'NGN', 'subtotal_minor' => 100, 'discount_minor' => 0, 'total_minor' => 100, 'expires_at' => now()->addHour()]);
    $this->postJson("/api/v1/admin/users/{$busy->id}/anonymise", ['reason' => 'erasure', 'confirm_email' => 'busy@example.com'])->assertStatus(409)->assertJsonPath('code', 'pending_order');
});
