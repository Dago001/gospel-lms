<?php

namespace App\Console\Commands;

use App\Services\Payments\ReconciliationService;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Re-verify unsettled payments with the gateway and expire stale orders';

    public function handle(ReconciliationService $svc): int
    {
        $r = $svc->run();
        $this->info(json_encode($r));

        return self::SUCCESS;
    }
}
