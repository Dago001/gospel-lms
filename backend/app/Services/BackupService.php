<?php

namespace App\Services;

use App\Models\BackupAuditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupService
{
    private function env(): array
    {
        $c = config('database.connections.'.config('database.default'));

        return ['PGPASSWORD' => (string) $c['password'], 'PGHOST' => (string) $c['host'], 'PGPORT' => (string) $c['port'], 'PGUSER' => (string) $c['username']];
    }

    private function run(array $cmd): void
    {
        $p = new Process($cmd, null, $this->env(), null, 3600);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException(trim($p->getErrorOutput()) ?: 'process failed');
        }
    }

    /** @return array<string,int> */
    public function tableCounts(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $out = [];
        foreach (config('backup.tables_checked') as $t) {
            $out[$t] = (int) $db->table($t)->count();
        }

        return $out;
    }

    public function backup(): BackupAuditRecord
    {
        $dir = config('backup.path');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $rec = BackupAuditRecord::create(['type' => 'backup', 'status' => 'failed', 'started_at' => now()]);
        try {
            $counts = $this->tableCounts();                      // taken before the dump: a restore must contain at least this much
            $file = rtrim($dir, '/').'/gospel-'.now()->utc()->format('Ymd-His').'.dump';
            $this->run([config('backup.pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file='.$file, config('database.connections.'.config('database.default').'.database')]);
            $sha = hash_file('sha256', $file);
            $rec->update(['status' => 'ok', 'location' => $file, 'size_bytes' => filesize($file), 'sha256' => $sha, 'finished_at' => now(), 'details' => ['table_counts' => $counts]]);
            if (config('backup.upload')) {
                Storage::disk('private')->put('backups/'.basename($file), fopen($file, 'r'));
            }
            $this->prune();
        } catch (\Throwable $e) {
            $rec->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 800), 'finished_at' => now()]);
        }

        return $rec->fresh();
    }

    private function prune(): void
    {
        foreach (glob(rtrim(config('backup.path'), '/').'/gospel-*.dump') ?: [] as $f) {
            if (filemtime($f) < now()->subDays(config('backup.keep_days'))->timestamp) {
                @unlink($f);
            }
        }
    }

    /**
     * Restore the dump into a scratch database, check it, and always drop the scratch database.
     * Never touches the live database.
     */
    public function verify(?string $file = null): BackupAuditRecord
    {
        $source = $file ? BackupAuditRecord::where('type', 'backup')->where('location', $file)->latest('started_at')->first()
            : BackupAuditRecord::where('type', 'backup')->where('status', 'ok')->latest('started_at')->first();
        $rec = BackupAuditRecord::create(['type' => 'restore_test', 'status' => 'failed', 'started_at' => now(), 'location' => $source?->location]);
        $scratch = 'gospel_restore_check_'.Str::lower(Str::random(8));
        $admin = config('database.default');
        try {
            if (! $source || ! is_file($source->location)) {
                throw new RuntimeException('No backup file to verify.');
            }
            if (hash_file('sha256', $source->location) !== $source->sha256) {
                throw new RuntimeException('Checksum mismatch: the backup file has been altered or corrupted.');
            }
            DB::connection($admin)->statement('CREATE DATABASE "'.$scratch.'"');
            $this->run([config('backup.pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error', '--dbname='.$scratch, $source->location]);

            config(['database.connections.restore_check' => array_merge(config('database.connections.'.$admin), ['database' => $scratch])]);
            DB::purge('restore_check');
            $restored = $this->tableCounts('restore_check');
            $expected = $source->details['table_counts'] ?? [];
            $short = [];
            foreach ($expected as $t => $n) {
                if (($restored[$t] ?? -1) < $n) {
                    $short[$t] = ['expected_at_least' => $n, 'restored' => $restored[$t] ?? null];
                }
            }
            $migrations = DB::connection('restore_check')->table('migrations')->count();
            if ($short || $migrations !== DB::table('migrations')->count()) {
                throw new RuntimeException('Restored data is incomplete: '.json_encode(['short' => $short, 'migrations_restored' => $migrations]));
            }
            $rec->update(['status' => 'ok', 'finished_at' => now(), 'sha256' => $source->sha256, 'size_bytes' => $source->size_bytes, 'details' => ['restored_counts' => $restored, 'migrations' => $migrations]]);
        } catch (\Throwable $e) {
            $rec->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 800), 'finished_at' => now()]);
        } finally {
            DB::purge('restore_check');
            try {
                DB::connection($admin)->statement('DROP DATABASE IF EXISTS "'.$scratch.'" WITH (FORCE)');
            } catch (\Throwable) {
            }
        }

        return $rec->fresh();
    }
}
