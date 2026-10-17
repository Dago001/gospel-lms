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

        if (\App\Support\DbCompat::mysql()) {
            return ['MYSQL_PWD' => (string) $c['password']];
        }

        return ['PGPASSWORD' => (string) $c['password'], 'PGHOST' => (string) $c['host'], 'PGPORT' => (string) $c['port'], 'PGUSER' => (string) $c['username']];
    }

    /** Connection flags shared by mysqldump and mysql (the password travels in MYSQL_PWD, never on the command line). */
    private function mysqlArgs(): array
    {
        $c = config('database.connections.'.config('database.default'));
        $args = ['--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], '--default-character-set=utf8mb4'];
        if (! empty($c['unix_socket'])) {
            $args = ['--socket='.$c['unix_socket'], '--user='.$c['username'], '--default-character-set=utf8mb4'];
        }

        return $args;
    }

    private function run(array $cmd, $input = null): void
    {
        $p = new Process($cmd, null, $this->env(), $input, 3600);
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
            $dbName = config('database.connections.'.config('database.default').'.database');
            if (\App\Support\DbCompat::mysql()) {
                $file = rtrim($dir, '/').'/gospel-'.now()->utc()->format('Ymd-His').'.sql';
                $this->run([config('backup.mysqldump'), ...$this->mysqlArgs(), '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--triggers', '--result-file='.$file, $dbName]);
            } else {
                $file = rtrim($dir, '/').'/gospel-'.now()->utc()->format('Ymd-His').'.dump';
                $this->run([config('backup.pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file='.$file, $dbName]);
            }
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
        foreach (array_merge(glob(rtrim(config('backup.path'), '/').'/gospel-*.dump') ?: [], glob(rtrim(config('backup.path'), '/').'/gospel-*.sql') ?: []) as $f) {
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
            if (\App\Support\DbCompat::mysql()) {
                try {
                    DB::connection($admin)->statement('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                } catch (\Throwable $e) {
                    // Shared hosting users usually cannot create databases. Fall back to a structural check of the dump, and say so.
                    return $this->verifyDumpFile($rec, $source);
                }
                $in = fopen($source->location, 'r');
                $this->run([config('backup.mysql'), ...$this->mysqlArgs(), $scratch], $in);
                fclose($in);
            } else {
                DB::connection($admin)->statement('CREATE DATABASE "'.$scratch.'"');
                $this->run([config('backup.pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error', '--dbname='.$scratch, $source->location]);
            }

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
                DB::connection($admin)->statement(\App\Support\DbCompat::mysql() ? 'DROP DATABASE IF EXISTS `'.$scratch.'`' : 'DROP DATABASE IF EXISTS "'.$scratch.'" WITH (FORCE)');
            } catch (\Throwable) {
            }
        }

        return $rec->fresh();
    }

    /**
     * Fallback when no scratch database can be created: confirm the checksum (already done by the caller), that mysqldump finished
     * ("Dump completed" footer) and that every important table's structure is present. This proves the file is whole, but is NOT a
     * full restore test, so the record says so. Run a full restore test on a machine that can create databases at least occasionally.
     */
    private function verifyDumpFile(BackupAuditRecord $rec, BackupAuditRecord $source): BackupAuditRecord
    {
        $tail = '';
        $fh = fopen($source->location, 'r');
        fseek($fh, max(0, filesize($source->location) - 400));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);
        if (! str_contains($tail, 'Dump completed')) {
            throw new RuntimeException('The dump file looks truncated (no "Dump completed" footer).');
        }
        $missing = [];
        $head = '';
        $fh = fopen($source->location, 'r');
        while (($line = fgets($fh)) !== false) {
            if (str_starts_with($line, 'CREATE TABLE')) {
                $head .= $line;
            }
        }
        fclose($fh);
        foreach (config('backup.tables_checked') as $t) {
            if (! str_contains($head, '`'.$t.'`')) {
                $missing[] = $t;
            }
        }
        if ($missing) {
            throw new RuntimeException('The dump is missing tables: '.implode(', ', $missing));
        }
        $rec->update(['status' => 'ok', 'finished_at' => now(), 'sha256' => $source->sha256, 'size_bytes' => $source->size_bytes,
            'details' => ['mode' => 'file check only (this database user cannot create a scratch database, so the dump was not restored)', 'tables_found' => count(config('backup.tables_checked'))]]);

        return $rec->fresh();
    }
}
