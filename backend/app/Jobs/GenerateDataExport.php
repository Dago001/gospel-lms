<?php

namespace App\Jobs;

use App\Models\DataExportRequest;
use App\Services\DataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $requestId) {}

    public function handle(DataExportService $svc): void
    {
        $r = DataExportRequest::find($this->requestId);
        if ($r && $r->status === 'requested') {
            $svc->generate($r);
        }
    }
}
