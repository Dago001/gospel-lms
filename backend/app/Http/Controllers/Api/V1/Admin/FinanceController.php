<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Services\AuditLogger;
use App\Services\Payments\GatewayException;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ReconciliationService;
use App\Services\Payments\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    private function meta($p): array
    {
        return ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()];
    }

    private function paymentRow(Payment $p): array
    {
        return ['id' => $p->id, 'reference' => $p->reference, 'status' => $p->status, 'amount_minor' => $p->amount_minor, 'currency' => $p->currency, 'channel' => $p->channel,
            'failure_reason' => $p->failure_reason, 'paid_at' => $p->paid_at?->toIso8601String(), 'created_at' => $p->created_at->toIso8601String(),
            'order' => $p->relationLoaded('order') ? ['id' => $p->order->id, 'number' => $p->order->number, 'status' => $p->order->status, 'customer' => $p->order->user ? ['id' => $p->order->user->id, 'name' => $p->order->user->name, 'email' => $p->order->user->email] : null] : null];
    }

    private function paymentQuery(Request $request)
    {
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:80'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return Payment::query()->with('order.user:id,name,email')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('reference', \App\Support\DbCompat::like(), '%'.addcslashes($v, '%_\\').'%')->orWhereHas('order', fn ($o) => $o->where('number', \App\Support\DbCompat::like(), '%'.addcslashes($v, '%_\\').'%'))))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->latest();
    }

    public function orders(Request $request): JsonResponse
    {
        $p = Order::with(['user:id,name,email', 'items'])->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))->latest()->paginate(25);

        return response()->json(['data' => $p->getCollection()->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'total_minor' => $o->total_minor, 'currency' => $o->currency,
            'customer' => ['name' => $o->user->name, 'email' => $o->user->email], 'items' => $o->items->pluck('title'), 'created_at' => $o->created_at->toIso8601String()])->values(), 'meta' => $this->meta($p)]);
    }

    public function payments(Request $request): JsonResponse
    {
        $p = $this->paymentQuery($request)->paginate(25);

        return response()->json(['data' => $p->getCollection()->map(fn ($x) => $this->paymentRow($x))->values(), 'meta' => $this->meta($p)]);
    }

    public function payment(Payment $payment): JsonResponse
    {
        $payment->load(['order.user:id,name,email', 'order.items', 'refunds', 'receipt']);

        return response()->json(['data' => $this->paymentRow($payment) + [
            'items' => $payment->order->items->map(fn ($i) => ['title' => $i->title, 'total_minor' => $i->total_minor]),
            'refundable_minor' => in_array($payment->status, ['success'], true) ? $payment->amount_minor - $payment->refundedMinor() : 0,
            'refunds' => $payment->refunds->map(fn ($r) => ['id' => $r->id, 'amount_minor' => $r->amount_minor, 'status' => $r->status, 'reason' => $r->reason, 'created_at' => $r->created_at->toIso8601String()]),
            'receipt_id' => $payment->receipt?->id]]);
    }

    public function webhooks(Request $request): JsonResponse
    {
        $p = PaymentWebhookEvent::query()->when($request->query('type'), fn ($q, $v) => $q->where('event_type', $v))->orderByDesc('received_at')->paginate(25);

        return response()->json(['data' => $p->getCollection()->map(fn ($e) => ['id' => $e->id, 'event_type' => $e->event_type, 'reference' => $e->reference, 'signature_valid' => $e->signature_valid,
            'attempts' => $e->attempts, 'processed_at' => $e->processed_at?->toIso8601String(), 'processing_error' => $e->processing_error, 'received_at' => $e->received_at->toIso8601String()])->values(), 'meta' => $this->meta($p)]);
    }

    public function exceptions(ReconciliationService $svc): JsonResponse
    {
        return response()->json(['data' => $svc->exceptions()]);
    }

    public function reconcile(Payment $payment, PaymentService $svc, AuditLogger $audit): JsonResponse
    {
        try {
            $p = $svc->verifyAndSettle($payment->reference);
        } catch (GatewayException $e) {
            return response()->json(['message' => 'The payment provider could not be reached: '.$e->getMessage(), 'code' => 'gateway_unavailable'], 503);
        }
        $audit->log('payment.reconciled', $p, new: ['status' => $p->status]);

        return response()->json(['data' => $this->paymentRow($p)]);
    }

    public function refund(Request $request, Payment $payment, RefundService $svc): JsonResponse
    {
        $d = $request->validate(['amount_minor' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:5', 'max:255'], 'revoke_access' => ['sometimes', 'boolean']]);
        $r = $svc->request($payment, $request->user(), $d['amount_minor'] ?? null, $d['reason'], (bool) ($d['revoke_access'] ?? false));

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status, 'amount_minor' => $r->amount_minor, 'failure_reason' => $r->failure_reason]], 201);
    }

    public function refunds(): JsonResponse
    {
        $p = Refund::with('payment:id,reference')->latest()->paginate(25);

        return response()->json(['data' => $p->getCollection()->map(fn ($r) => ['id' => $r->id, 'payment_reference' => $r->payment->reference, 'amount_minor' => $r->amount_minor, 'currency' => $r->currency,
            'status' => $r->status, 'reason' => $r->reason, 'created_at' => $r->created_at->toIso8601String()])->values(), 'meta' => $this->meta($p)]);
    }

    /** Revenue and transaction totals computed from the ledger tables. Currency-grouped; no invented numbers. */
    public function summary(Request $request): JsonResponse
    {
        $r = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $r['from'] ?? now()->subDays(30)->toDateString();
        $to = ($r['to'] ?? now()->toDateString()).' 23:59:59';
        $byStatus = Payment::whereBetween('created_at', [$from, $to])->select('status', 'currency', DB::raw('count(*) as count'), DB::raw('coalesce(sum(amount_minor),0) as total_minor'))->groupBy('status', 'currency')->get();
        $refunds = Refund::where('status', 'processed')->whereBetween('processed_at', [$from, $to])->select('currency', DB::raw('count(*) as count'), DB::raw('coalesce(sum(amount_minor),0) as total_minor'))->groupBy('currency')->get();
        $daily = Payment::whereIn('status', ['success'])->whereBetween('paid_at', [$from, $to])->select(DB::raw(\App\Support\DbCompat::lagosDay('paid_at') . ' as day'), 'currency', DB::raw('count(*) as count'), DB::raw('sum(amount_minor) as total_minor'))->groupBy('day', 'currency')->orderBy('day')->get();

        return response()->json(['data' => ['from' => $from, 'to' => substr($to, 0, 10), 'payments_by_status' => $byStatus, 'refunds_processed' => $refunds, 'daily_revenue' => $daily]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->paymentQuery($request);
        app(AuditLogger::class)->log('finance.export', null, new: $request->only(['status', 'from', 'to']));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['reference', 'order', 'customer_email', 'status', 'currency', 'amount_major', 'channel', 'paid_at', 'created_at']);
            $query->lazyById(500)->each(function ($p) use ($out) {
                $cell = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;   // CSV formula-injection guard
                fputcsv($out, [$p->reference, $p->order->number, $cell($p->order->user->email), $p->status, $p->currency, number_format($p->amount_minor / 100, 2, '.', ''), $p->channel, $p->paid_at?->toIso8601String(), $p->created_at->toIso8601String()]);
            });
            fclose($out);
        }, 'transactions-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    // ---- coupons ----
    public function coupons(): JsonResponse
    {
        return response()->json(['data' => Coupon::withCount(['redemptions as redeemed_count' => fn ($q) => $q->where('status', 'redeemed')])->latest()->get()]);
    }

    public function storeCoupon(Request $request, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'], 'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'integer', 'min:1', 'max:100000000000', fn ($a, $v, $fail) => $request->input('type') === 'percent' && $v > 100 ? $fail('A percentage cannot exceed 100.') : null],
            'max_redemptions' => ['nullable', 'integer', 'min:1'], 'per_user_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'course_ids' => ['nullable', 'array'], 'course_ids.*' => ['uuid', 'exists:courses,id'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        if (Coupon::whereRaw('lower(code) = ?', [mb_strtolower($d['code'])])->exists()) {
            return response()->json(['message' => 'That coupon code already exists.', 'errors' => ['code' => ['That coupon code already exists.']]], 422);
        }
        $c = new Coupon($d);
        $c->created_by = $request->user()->id;
        $c->save();
        $audit->log('coupon.created', $c, new: $d);

        return response()->json(['data' => $c], 201);
    }

    public function updateCoupon(Request $request, Coupon $coupon, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['active' => ['sometimes', 'boolean'], 'ends_at' => ['nullable', 'date'], 'max_redemptions' => ['nullable', 'integer', 'min:1']]);
        $coupon->update($d);
        $audit->log('coupon.updated', $coupon, new: $d);

        return response()->json(['data' => $coupon]);
    }
}
