<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class ReceiptService
{
    /** Idempotent: UNIQUE(payment_id) guarantees one receipt per payment. */
    public function issue(Payment $payment, Order $order, User $user): Receipt
    {
        if ($r = Receipt::where('payment_id', $payment->id)->first()) {
            return $r;
        }
        do {
            $number = 'RCT-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Receipt::where('number', $number)->exists());

        return Receipt::create([
            'number' => $number, 'payment_id' => $payment->id, 'user_id' => $user->id, 'issued_at' => now(),
            'data' => [
                'customer' => ['name' => $user->name, 'email' => $user->email],
                'order_number' => $order->number, 'payment_reference' => $payment->reference, 'channel' => $payment->channel,
                'currency' => $order->currency, 'subtotal_minor' => $order->subtotal_minor, 'discount_minor' => $order->discount_minor, 'total_minor' => $order->total_minor,
                'paid_at' => ($payment->paid_at ?? now())->toIso8601String(),
                'items' => $order->items->map(fn ($i) => ['title' => $i->title, 'unit_price_minor' => $i->unit_price_minor, 'discount_minor' => $i->discount_minor, 'total_minor' => $i->total_minor])->all(),
            ],
        ]);
    }

    public function pdf(Receipt $receipt): string
    {
        return Pdf::loadView('pdf.receipt', ['receipt' => $receipt, 'd' => $receipt->data, 'academy' => \App\Services\Settings::academy()])->setPaper('a4')->output();
    }
}
