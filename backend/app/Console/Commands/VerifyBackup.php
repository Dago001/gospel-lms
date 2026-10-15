<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class VerifyBackup extends Command
{
    protected $signature = 'gospel:backup-verify {file? : Path of a recorded backup (default: latest successful)}';

    protected $description = 'Restore a backup into a throwaway database, verify it, then drop it. The live database is never touched.';

    public function handle(BackupService $svc): int
    {
        $r = $svc->verify($this->argument('file'));
        $r->status === 'ok' ? $this->info('Restore test OK: '.json_encode($r->details['restored_counts'] ?? [])) : $this->error("Restore test FAILED: {$r->error}");

        return $r->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
