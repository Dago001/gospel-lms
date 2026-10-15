<?php

use App\Models\BackupAuditRecord;
use App\Models\Course;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uses the real pg_dump / pg_restore against the PostgreSQL test database. These tests commit data (the dump runs on a
 * separate connection), so they do not use RefreshDatabase and rebuild the schema afterwards.
 */
uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $this->dir = sys_get_temp_dir().'/gospel-backup-test-'.uniqid();
    config(['backup.path' => $this->dir]);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder', '--force' => true]);
    User::factory()->count(3)->withRole('student')->create();
    Course::factory()->published()->count(2)->create();
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->dir);
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('backs up the database with a checksum and row counts', function () {
    $this->artisan('gospel:backup')->assertSuccessful();
    $b = BackupAuditRecord::where('type', 'backup')->firstOrFail();
    expect($b->status)->toBe('ok')->and($b->size_bytes)->toBeGreaterThan(1000)->and($b->sha256)->toBe(hash_file('sha256', $b->location))
        ->and($b->details['table_counts']['users'])->toBe(3)->and($b->details['table_counts']['courses'])->toBe(2);
});

it('proves the backup restores: data lands in a scratch database that is then dropped, and live data is untouched', function () {
    $this->artisan('gospel:backup')->assertSuccessful();
    User::factory()->create();                                   // written after the backup; must not appear in, or be affected by, the restore
    $this->artisan('gospel:backup-verify')->assertSuccessful();
    $r = BackupAuditRecord::where('type', 'restore_test')->firstOrFail();
    expect($r->status)->toBe('ok')->and($r->details['restored_counts']['users'])->toBe(3)->and($r->details['restored_counts']['courses'])->toBe(2)->and($r->details['migrations'])->toBeGreaterThan(5);
    expect(User::count())->toBe(4);                              // live database unchanged
    $left = DB::select("select datname from pg_database where datname like 'gospel_restore_check_%'");
    expect($left)->toBe([]);                                     // the scratch database was removed
});

it('detects a corrupted or altered backup file', function () {
    $this->artisan('gospel:backup')->assertSuccessful();
    $b = BackupAuditRecord::where('type', 'backup')->firstOrFail();
    file_put_contents($b->location, 'tampered', FILE_APPEND);
    $this->artisan('gospel:backup-verify')->assertFailed();
    expect(BackupAuditRecord::where('type', 'restore_test')->firstOrFail())->status->toBe('failed')->error->toContain('Checksum mismatch');
    expect(DB::select("select datname from pg_database where datname like 'gospel_restore_check_%'"))->toBe([]);
});

it('fails the restore test if the backup holds less data than existed when it was taken', function () {
    $this->artisan('gospel:backup')->assertSuccessful();
    $b = BackupAuditRecord::where('type', 'backup')->firstOrFail();
    $counts = $b->details['table_counts'];
    $counts['users'] += 5;                                       // pretend the backup is missing rows
    $b->update(['details' => ['table_counts' => $counts]]);
    $this->artisan('gospel:backup-verify')->assertFailed();
    expect(BackupAuditRecord::where('type', 'restore_test')->first()->error)->toContain('incomplete');
});

it('reports a clear failure when there is nothing to verify', function () {
    $this->artisan('gospel:backup-verify')->assertFailed();
    expect(BackupAuditRecord::where('type', 'restore_test')->first()->error)->toContain('No backup file');
});
