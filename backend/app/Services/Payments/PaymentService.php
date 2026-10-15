<?php

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentConfirmed;
use App\Services\AuditLogger;
use App\Services\CouponService;
use App\Services\EnrolmentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private PaystackClient $gateway, private EnrolmentService $enrolments, private CouponService $coupons,
        private ReceiptService $receipts, private AuditLogger $audit,
    ) {}

    /** Server-side initialisation. Reuses a recent open payment for the order instead of opening a second one. */
    public function initialize(Order $order, User $user): Payment
    {
        $open = $order->payments()->where('status', 'initialized')->whereNotNull('authorization_url')->where('created_at', '>', now()->subMinutes(30))->latest()->first();
        if ($open) {
            return $open;
        }
        $payment = Payment::create([
            'order_id' => $order->id, 'provider' => 'paystack', 'reference' => 'GL'.Str::upper((string) Str::ulid()),
            'amount_minor' => $order->total_minor, 'currency' => $order->currency, 'status' => 'initialized',
        ]);
        try {
            $data = $this->gateway->initialize(
                $user->email, $payment->amount_minor, $payment->currency, $payment->reference,
                rtrim(config('app.frontend_url'), '/').'/checkout/callback',
                ['order_id' => $order->id, 'order_number' => $order->number, 'user_id' => $user->id],
            );
        } catch (GatewayException $e) {
            $payment->update(['status' => 'failed', 'failure_reason' => 'initialisation_failed']);
            Log::warning('payment.initialize_failed', ['payment' => $payment->id, 'retryable' => $e->retryable]);
            throw new DomainException('The payment service is unavailable right now. Please try again shortly.', 'gateway_unavailable', 503);
        }
        $payment->update(['authorization_url' => $data['authorization_url'], 'access_code' => $data['access_code'] ?? null]);

        return $payment;
    }

    /** Orders that are fully discounted need no gateway round-trip. Still transactional and idempotent. */
    public function settleFree(Order $order): Payment
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($existing = $order->payments()->whereIn('status', ['success', 'free'])->first()) {
                return $existing;
            }
            $payment = Payment::create(['order_id' => $order->id, 'provider' => 'none', 'reference' => 'GLFREE'.Str::upper((string) Str::ulid()),
                'amount_minor' => 0, 'currency' => $order->currency, 'status' => 'free', 'paid_at' => now()]);
            $this->fulfil($order, $payment);

            return $payment;
        });
    }

    /** Asks the gateway for the truth about a reference, then settles. Never trusts a client or webhook body for the outcome. */
    public function verifyAndSettle(string $reference): Payment
    {
        $payment = Payment::where('reference', $reference)->firstOrFail();
        if ($payment->isSettled()) {
            return $payment;
        }
        $gw = $this->gateway->verify($reference);   // may throw GatewayException (caller decides whether to retry)
        if ($gw === null) {
            $payment->update(['last_verified_at' => now()]);   // gateway has never seen this reference (checkout not started)

            return $payment;
        }

        return $this->settle($reference, $gw);
    }

    /** @param array<string,mixed> $gw the gateway's verification payload */
    public function settle(string $reference, array $gw): Payment
    {
        try {
            return DB::transaction(fn () => $this->settleLocked($reference, $gw));
        } catch (UniqueConstraintViolationException $e) {
            // The same gateway transaction (or a second success for the same order) tried to settle twice.
            return DB::transaction(function () use ($reference, $gw) {
                $p = Payment::where('reference', $reference)->lockForUpdate()->firstOrFail();
                if (! $p->isSettled()) {
                    $p->update(['status' => 'mismatch', 'failure_reason' => 'duplicate_gateway_transaction', 'last_verified_at' => now(), 'verification' => $this->sanitise($gw)]);
                    $this->audit->log('payment.duplicate_detected', $p, new: ['reference' => $reference], actorId: $p->order->user_id);
                }

                return $p;
            });
        }
    }

    private function settleLocked(string $reference, array $gw): Payment
    {
        $payment = Payment::where('reference', $reference)->lockForUpdate()->firstOrFail();
        if ($payment->isSettled()) {
            return $payment;                                    // idempotent: second delivery / second verify is a no-op
        }
        $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
        $status = $gw['status'] ?? null;
        $payment->fill(['last_verified_at' => now(), 'gateway_response' => Str::limit((string) ($gw['gateway_response'] ?? ''), 250, ''),
            'channel' => $gw['channel'] ?? null, 'verification' => $this->sanitise($gw)]);

        if ($status === 'success') {
            $problems = $this->mismatches($payment, $order, $gw);
            if ($order->status === 'paid' || $order->payments()->whereIn('status', ['success', 'free'])->where('id', '!=', $payment->id)->exists()) {
                $problems[] = 'duplicate_payment_for_order';     // customer paid twice; finance must refund the extra charge
            }
            if ($problems) {
                $payment->fill(['status' => 'mismatch', 'failure_reason' => implode(',', $problems)])->save();
                $this->audit->log('payment.mismatch', $payment, new: ['problems' => $problems], actorId: $order->user_id);
                Log::warning('payment.mismatch', ['payment' => $payment->id, 'problems' => $problems]);

                return $payment;
            }
            $payment->fill(['status' => 'success', 'provider_transaction_id' => (string) $gw['id'], 'paid_at' => isset($gw['paid_at']) ? \Illuminate\Support\Carbon::parse($gw['paid_at']) : now()])->save();
            $this->fulfil($order, $payment);

            return $payment;
        }
        if ($status === 'failed') {
            $payment->fill(['status' => 'failed', 'failure_reason' => Str::limit((string) ($gw['gateway_response'] ?? 'failed'), 250, '')])->save();
            if ($order->status === 'pending' && ! $order->payments()->where('id', '!=', $payment->id)->whereIn('status', ['initialized', 'success'])->exists()) {
                $order->update(['status' => 'failed']);
                $this->coupons->release($order->id);
            }
            $this->audit->log('payment.failed', $payment, actorId: $order->user_id);
        } elseif ($status === 'abandoned') {
            $payment->fill(['status' => 'abandoned'])->save();
        } else {
            $payment->save();                                   // ongoing / pending: keep waiting, reconciliation will revisit
        }

        return $payment;
    }

    /** @return string[] */
    private function mismatches(Payment $payment, Order $order, array $gw): array
    {
        $p = [];
        if (($gw['reference'] ?? null) !== $payment->reference) $p[] = 'reference_mismatch';
        if (! isset($gw['amount']) || (int) $gw['amount'] !== $payment->amount_minor || $payment->amount_minor !== $order->total_minor) $p[] = 'amount_mismatch';
        if (strtoupper((string) ($gw['currency'] ?? '')) !== $payment->currency || $payment->currency !== $order->currency) $p[] = 'currency_mismatch';
        if (empty($gw['id'])) $p[] = 'missing_transaction_id';
        $meta = is_array($gw['metadata'] ?? null) ? $gw['metadata'] : [];
        if (($meta['order_id'] ?? null) !== $order->id) $p[] = 'order_mismatch';
        // A test-mode charge must never grant access in live mode (and vice versa).
        $mode = config('services.paystack.mode');
        if (isset($gw['domain']) && $gw['domain'] !== $mode) $p[] = 'gateway_mode_mismatch';

        return $p;
    }

    /** Grants access and records everything. Runs inside the settlement transaction. */
    private function fulfil(Order $order, Payment $payment): void
    {
        $order->update(['status' => 'paid', 'paid_at' => $payment->paid_at ?? now()]);
        $this->coupons->confirm($order->id);
        $user = User::findOrFail($order->user_id);
        foreach ($order->items()->with('course')->get() as $item) {
            $enrollment = $this->enrolments->enrol($user, $item->course, 'payment', $item->id, enforceRules: false);
            if ($enrollment->order_item_id !== $item->id) {
                $enrollment->forceFill(['order_item_id' => $item->id, 'source' => 'payment'])->save();
            }
        }
        $receipt = $this->receipts->issue($payment->fresh(), $order, $user);
        $this->audit->log('payment.succeeded', $payment, new: ['order' => $order->number, 'amount_minor' => $payment->amount_minor], actorId: $user->id);
        DB::afterCommit(fn () => $user->notify(new PaymentConfirmed($order->number, $payment->amount_minor, $payment->currency, $receipt->number)));
    }

    /** Keep only what finance needs; drop card/customer detail we have no reason to store. */
    private function sanitise(array $gw): array
    {
        return array_intersect_key($gw, array_flip(['id', 'status', 'reference', 'amount', 'currency', 'channel', 'domain', 'gateway_response', 'paid_at', 'created_at', 'metadata', 'fees']));
    }
}
