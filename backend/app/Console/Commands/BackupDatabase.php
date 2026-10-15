<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'gospel:backup';

    protected $description = 'Dump the database (pg_dump custom format), record checksum and row counts';

    public function handle(BackupService $svc): int
    {
        $r = $svc->backup();
        $r->status === 'ok' ? $this->info("Backup OK: {$r->location} ({$r->size_bytes} bytes, sha256 {$r->sha256})") : $this->error("Backup FAILED: {$r->error}");

        return $r->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
