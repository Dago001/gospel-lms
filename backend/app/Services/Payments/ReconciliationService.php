<?php

namespace App\Services\Payments;

use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Services\CouponService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationService
{
    public function __construct(private PaymentService $payments, private CouponService $coupons) {}

    /**
     * Re-verifies payments that may have missed their webhook (delayed events, interrupted callbacks, abandoned checkout),
     * and expires stale orders. Safe to run repeatedly and concurrently.
     *
     * @return array{checked:int, settled:int, errors:int, expired_orders:int}
     */
    public function run(): array
    {
        $r = ['checked' => 0, 'settled' => 0, 'errors' => 0, 'expired_orders' => 0];
        Payment::whereIn('status', ['initialized', 'abandoned'])->where('provider', 'paystack')
            ->where('created_at', '<', now()->subMinutes(10))->where('created_at', '>', now()->subDays(3))
            ->orderBy('created_at')->limit(200)->get()->each(function (Payment $p) use (&$r) {
                $r['checked']++;
                try {
                    $res = $this->payments->verifyAndSettle($p->reference);
                    $res->isSettled() && $r['settled']++;
                } catch (GatewayException $e) {
                    $r['errors']++;
                    Log::warning('reconcile.gateway_error', ['payment' => $p->id, 'why' => $e->getMessage()]);
                }
            });
        // Orders unpaid an hour past expiry (after the verification above) are closed and their coupon reservation freed.
        Order::where('status', 'pending')->where('expires_at', '<', now()->subHour())->each(function (Order $o) use (&$r) {
            DB::transaction(function () use ($o, &$r) {
                $o = Order::whereKey($o->id)->lockForUpdate()->first();
                if ($o->status === 'pending') {
                    $o->update(['status' => 'expired']);
                    $this->coupons->release($o->id);
                    $r['expired_orders']++;
                }
            });
        });

        return $r;
    }

    /** Everything finance should look at. Each item has a stable `kind` for filtering in the UI. */
    public function exceptions(): array
    {
        $groups = [
            Payment::where('status', 'mismatch')->with('order:id,number,user_id')->latest()->limit(100)->get()->map(fn ($p) => [
                'kind' => 'payment_mismatch', 'payment_id' => $p->id, 'reference' => $p->reference, 'order' => $p->order->number, 'detail' => $p->failure_reason, 'at' => $p->updated_at->toIso8601String()]),
            Payment::where('status', 'initialized')->where('created_at', '<', now()->subHour())->latest()->limit(100)->get()->map(fn ($p) => [
                'kind' => 'stuck_initialized', 'payment_id' => $p->id, 'reference' => $p->reference, 'detail' => 'No outcome after 1 hour', 'at' => $p->created_at->toIso8601String()]),
            Order::where('status', 'paid')->whereHas('items', fn ($q) => $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('course_enrollments')
                ->whereColumn('course_enrollments.order_item_id', 'order_items.id')))->limit(100)->get()->map(fn ($o) => [
                'kind' => 'paid_without_enrolment', 'order' => $o->number, 'detail' => 'Paid order has an item with no enrolment', 'at' => $o->paid_at?->toIso8601String()]),
            PaymentWebhookEvent::whereNotNull('processing_error')->latest('received_at')->limit(100)->get()->map(fn ($e) => [
                'kind' => 'webhook_error', 'event_id' => $e->id, 'reference' => $e->reference, 'detail' => $e->processing_error, 'at' => $e->received_at->toIso8601String()]),
            PaymentWebhookEvent::where('signature_valid', false)->latest('received_at')->limit(50)->get()->map(fn ($e) => [
                'kind' => 'invalid_signature', 'event_id' => $e->id, 'detail' => 'Webhook rejected: bad signature', 'at' => $e->received_at->toIso8601String()]),
            Refund::whereIn('status', ['review', 'failed'])->latest()->limit(100)->get()->map(fn ($r) => [
                'kind' => 'refund_'.$r->status, 'refund_id' => $r->id, 'payment_id' => $r->payment_id, 'detail' => $r->failure_reason, 'at' => $r->updated_at->toIso8601String()]),
        ];

        return collect($groups)->flatten(1)->values()->all();
    }
}
