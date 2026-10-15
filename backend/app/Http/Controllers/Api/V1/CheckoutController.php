<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\GatewayException;
use App\Services\Payments\PaymentService;
use App\Services\Payments\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(private CheckoutService $checkout, private PaymentService $payments) {}

    private function rules(): array
    {
        return ['course_ids' => ['required', 'array', 'min:1', 'max:10'], 'course_ids.*' => ['uuid', 'distinct'], 'coupon_code' => ['nullable', 'string', 'max:40']];
    }

    private function requireVerified(Request $request): void
    {
        if (! $request->user()->hasVerifiedEmail()) {
            throw new DomainException('Verify your email address before purchasing.', 'email_unverified', 403);
        }
    }

    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules());
        $q = $this->checkout->quote($request->user(), $d['course_ids'], $d['coupon_code'] ?? null);

        return response()->json(['data' => ['currency' => $q['currency'], 'subtotal_minor' => $q['subtotal_minor'], 'discount_minor' => $q['discount_minor'], 'total_minor' => $q['total_minor'],
            'items' => $q['courses']->map(fn ($c) => ['course_id' => $c->id, 'title' => $c->title, 'unit_price_minor' => $q['lines'][$c->id], 'discount_minor' => $q['discounts'][$c->id] ?? 0])->values()]]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->requireVerified($request);
        $d = $request->validate($this->rules());
        $r = $this->checkout->begin($request->user(), $d['course_ids'], $d['coupon_code'] ?? null);

        return response()->json(['data' => $this->orderData($r['order']) + [
            'payment' => ['reference' => $r['payment']->reference, 'status' => $r['payment']->status, 'authorization_url' => $r['authorization_url']],
        ]], 201);
    }

    /** Called by the SPA after the gateway redirects back. The outcome is whatever the gateway says, not what the browser claims. */
    public function verify(Request $request, string $reference): JsonResponse
    {
        $payment = Payment::where('reference', $reference)->with('order')->firstOrFail();
        abort_unless($payment->order->user_id === $request->user()->id, 404);   // owner-scoped; no existence oracle
        try {
            $payment = $this->payments->verifyAndSettle($reference);
        } catch (GatewayException) {
            return response()->json(['data' => ['status' => 'pending', 'message' => 'We could not reach the payment provider. Your payment will be confirmed automatically.']], 202);
        }
        $order = $payment->order->fresh('items');

        return response()->json(['data' => ['status' => $payment->status, 'order' => $this->orderData($order), 'receipt_id' => $payment->receipt?->id]]);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = Order::where('user_id', $request->user()->id)->with('items')->latest()->paginate(20);

        return response()->json(['data' => $orders->getCollection()->map(fn ($o) => $this->orderData($o))->values(), 'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()]]);
    }

    public function receipts(Request $request): JsonResponse
    {
        $rows = Receipt::where('user_id', $request->user()->id)->latest('issued_at')->get();

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'issued_at' => $r->issued_at->toIso8601String(),
            'total_minor' => $r->data['total_minor'], 'currency' => $r->data['currency'], 'order_number' => $r->data['order_number']])]);
    }

    public function receiptPdf(Request $request, Receipt $receipt, ReceiptService $svc)
    {
        $u = $request->user();
        abort_unless($receipt->user_id === $u->id || $u->hasPermission('finance.view'), 404);

        return response($svc->pdf($receipt), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$receipt->number.'.pdf"']);
    }

    private function orderData(Order $o): array
    {
        return ['order_id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'currency' => $o->currency, 'subtotal_minor' => $o->subtotal_minor,
            'discount_minor' => $o->discount_minor, 'total_minor' => $o->total_minor, 'expires_at' => $o->expires_at?->toIso8601String(), 'paid_at' => $o->paid_at?->toIso8601String(),
            'items' => $o->items->map(fn ($i) => ['course_id' => $i->course_id, 'title' => $i->title, 'total_minor' => $i->total_minor])->values()];
    }
}
