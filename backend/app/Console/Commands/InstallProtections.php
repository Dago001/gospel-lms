<?php

namespace App\Console\Commands;

use App\Support\DbCompat;
use Illuminate\Console\Command;

class InstallProtections extends Command
{
    protected $signature = 'gospel:install-protections';

    protected $description = 'Create any missing database triggers that make the audit, grade-change and certificate-revocation logs append-only.';

    public function handle(): int
    {
        foreach (DbCompat::appendOnlyActive() as $table => $active) {
            if ($active) {
                $this->line("$table: protected");

                continue;
            }
            DbCompat::dropAppendOnly($table);
            DbCompat::appendOnly($table);
        }
        $missing = array_keys(array_filter(DbCompat::appendOnlyActive(), fn ($a) => ! $a));
        if ($missing) {
            $this->error('Still unprotected: '.implode(', ', $missing).'. The database user needs the TRIGGER privilege (and log_bin_trust_function_creators=1 when binary logging is on).');

            return self::FAILURE;
        }
        $this->info('All append-only protections are in place.');

        return self::SUCCESS;
    }
}
