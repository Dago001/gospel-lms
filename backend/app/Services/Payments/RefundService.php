<?php

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Models\CourseEnrollment;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RefundService
{
    public function __construct(private PaystackClient $gateway, private AuditLogger $audit) {}

    /** Refund up to the unrefunded balance. Ambiguous gateway outcomes freeze the payment ('review') to prevent double refunds. */
    public function request(Payment $payment, User $by, ?int $amountMinor, string $reason, bool $revokeAccess): Refund
    {
        $refund = DB::transaction(function () use ($payment, $by, $amountMinor, $reason, $revokeAccess) {
            $p = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $gatewaySuccess = $p->status === 'success' || ($p->status === 'mismatch' && ($p->verification['status'] ?? null) === 'success');
            if (! $gatewaySuccess || $p->provider !== 'paystack') {
                throw new DomainException('Only successfully charged payments can be refunded.', 'not_refundable', 409);
            }
            if ($p->refunds()->where('status', 'review')->exists()) {
                throw new DomainException('A previous refund on this payment needs manual review first.', 'refund_in_review', 409);
            }
            $balance = $p->amount_minor - $p->refundedMinor();
            $amount = $amountMinor ?? $balance;
            if ($amount <= 0 || $amount > $balance) {
                throw new DomainException('The refund amount exceeds the refundable balance.', 'refund_exceeds_balance', 422, ['refundable_minor' => $balance]);
            }
            if ($revokeAccess && $amount !== $p->amount_minor) {
                throw new DomainException('Access can only be revoked on a full refund.', 'partial_revoke_not_allowed');
            }

            return Refund::create(['payment_id' => $p->id, 'order_id' => $p->order_id, 'amount_minor' => $amount, 'currency' => $p->currency, 'status' => 'requested',
                'reason' => $reason, 'revoke_access' => $revokeAccess, 'requested_by' => $by->id, 'idempotency_key' => (string) Str::uuid()]);
        });
        $this->audit->log('refund.requested', $refund, new: ['amount_minor' => $refund->amount_minor, 'payment' => $payment->id], reason: $reason, actorId: $by->id);

        try {
            $data = $this->gateway->refund($payment->reference, $refund->amount_minor, $refund->currency, "Refund {$refund->id}: {$reason}");
            $status = ($data['status'] ?? 'pending') === 'processed' ? 'processed' : 'pending';
            $refund->update(['status' => $status, 'provider_refund_id' => isset($data['id']) ? (string) $data['id'] : null, 'processed_at' => $status === 'processed' ? now() : null]);
            if ($status === 'processed') {
                $this->applyProcessed($refund);
            }
        } catch (GatewayException $e) {
            if ($e->retryable) {
                // We cannot know whether the gateway accepted it. Freeze rather than risk refunding twice.
                $refund->update(['status' => 'review', 'failure_reason' => 'gateway_unreachable_outcome_unknown']);
                Log::error('refund.outcome_unknown', ['refund' => $refund->id]);
            } else {
                $refund->update(['status' => 'failed', 'failure_reason' => Str::limit($e->getMessage(), 250, '')]);
            }
        }

        return $refund->fresh();
    }

    /** Applies a refund.* webhook payload. Idempotent. */
    public function applyGatewayEvent(string $event, array $data): void
    {
        $ref = $data['transaction_reference'] ?? ($data['transaction']['reference'] ?? null);
        $refund = null;
        if (! empty($data['id'])) {
            $refund = Refund::where('provider_refund_id', (string) $data['id'])->first();
        }
        if (! $refund && $ref) {
            $payment = Payment::where('reference', $ref)->first();
            $refund = $payment?->refunds()->whereIn('status', ['pending', 'requested', 'review'])->where('amount_minor', (int) ($data['amount'] ?? 0))->oldest()->first();
        }
        if (! $refund) {
            throw new DomainException('Refund event does not match any refund request.', 'unknown_refund', 404);
        }
        DB::transaction(function () use ($refund, $event, $data) {
            $r = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if (in_array($r->status, ['processed'], true) && $event === 'refund.processed') {
                return;                                          // duplicate delivery
            }
            if (! $r->provider_refund_id && ! empty($data['id'])) {
                $r->provider_refund_id = (string) $data['id'];
            }
            if ($event === 'refund.processed') {
                $r->fill(['status' => 'processed', 'processed_at' => now(), 'failure_reason' => null])->save();
                $this->applyProcessed($r);
            } elseif ($event === 'refund.failed') {
                $r->fill(['status' => 'failed', 'failure_reason' => 'gateway_reported_failure'])->save();
                $this->audit->log('refund.failed', $r, actorId: $r->requested_by);
            } else {
                $r->fill(['status' => $r->status === 'processed' ? 'processed' : 'pending'])->save();
            }
        });
    }

    private function applyProcessed(Refund $refund): void
    {
        DB::transaction(function () use ($refund) {
            $payment = Payment::whereKey($refund->payment_id)->first();
            $order = $payment->order;
            $total = (int) $payment->refunds()->where('status', 'processed')->sum('amount_minor');
            $order->update(['status' => $total >= $payment->amount_minor ? 'refunded' : 'partially_refunded']);
            if ($refund->revoke_access && $total >= $payment->amount_minor) {
                $itemIds = OrderItem::where('order_id', $order->id)->pluck('id');
                CourseEnrollment::whereIn('order_item_id', $itemIds)->whereIn('status', ['active', 'suspended'])->update(['status' => 'cancelled']);
            }
            $this->audit->log('refund.processed', $refund, new: ['amount_minor' => $refund->amount_minor, 'access_revoked' => $refund->revoke_access && $total >= $payment->amount_minor], actorId: $refund->requested_by);
        });
    }
}
