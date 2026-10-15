<?php

namespace App\Jobs;

use App\Models\LiveSession;
use App\Services\Live\LiveSessionService;
use App\Services\Payments\GatewayException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Zoom's participant report can lag a few minutes after a meeting; retry with backoff. Idempotent (sets, never increments). */
class SyncZoomAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(public string $sessionId) {}

    public function backoff(): array
    {
        return [120, 300, 900, 1800, 3600, 7200];
    }

    public function handle(LiveSessionService $svc): void
    {
        $s = LiveSession::find($this->sessionId);
        if (! $s || $s->provider !== 'zoom' || ! $s->provider_meeting_id || $s->status === 'cancelled') {
            return;
        }
        try {
            $svc->syncAttendance($s);
        } catch (GatewayException $e) {
            if ($e->retryable || $e->httpStatus === 404) {   // 404: report not generated yet
                $svc->logFailure($s, $e);
                $this->release(300);

                return;
            }
            throw $e;
        }
    }
}
