<?php

namespace App\Jobs;

use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\GatewayException;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Idempotent: re-running for an already-processed event does nothing. */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public string $eventId) {}

    public function backoff(): array
    {
        return [30, 60, 300, 900, 3600];
    }

    public function handle(PaymentService $payments, RefundService $refunds): void
    {
        $event = PaymentWebhookEvent::find($this->eventId);
        if (! $event || $event->processed_at || ! $event->signature_valid) {
            return;
        }
        $event->increment('attempts');
        $data = $event->payload['data'] ?? [];
        try {
            match (true) {
                $event->event_type === 'charge.success' => $this->charge($payments, $data),
                str_starts_with($event->event_type, 'refund.') => $refunds->applyGatewayEvent($event->event_type, $data),
                default => null,                                 // events we do not act on are acknowledged
            };
            $event->update(['processed_at' => now(), 'processing_error' => null]);
        } catch (GatewayException $e) {
            $event->update(['processing_error' => 'gateway: '.$e->getMessage()]);
            if ($e->retryable) {
                throw $e;                                        // queue retries with backoff
            }
            $event->update(['processed_at' => now()]);
        } catch (DomainException $e) {
            $event->update(['processed_at' => now(), 'processing_error' => $e->getMessage()]);   // permanent; visible to finance
            Log::warning('webhook.unprocessable', ['event' => $event->id, 'why' => $e->getMessage()]);
        }
    }

    private function charge(PaymentService $payments, array $data): void
    {
        $ref = $data['reference'] ?? null;
        if (! $ref || ! Payment::where('reference', $ref)->exists()) {
            throw new DomainException('charge.success for an unknown reference.', 'unknown_reference', 404);
        }
        $payments->verifyAndSettle($ref);   // outcome comes from the gateway verify API, never from the webhook body
    }

    public function failed(\Throwable $e): void
    {
        PaymentWebhookEvent::whereKey($this->eventId)->update(['processing_error' => 'exhausted retries: '.substr($e->getMessage(), 0, 400)]);
    }
}
