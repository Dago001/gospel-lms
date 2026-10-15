<?php

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CouponService;
use App\Services\CoursePricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(private CoursePricing $pricing, private CouponService $coupons, private PaymentService $payments, private AuditLogger $audit) {}

    /** Prices a basket on the server. Nothing the client sends is trusted except course ids and the coupon code. */
    public function quote(User $user, array $courseIds, ?string $couponCode, bool $lock = false): array
    {
        $courseIds = array_values(array_unique($courseIds));
        $courses = Course::whereIn('id', $courseIds)->where('status', 'published')->get();
        if ($courses->count() !== count($courseIds)) {
            throw new DomainException('One or more courses are not available for purchase.', 'course_unavailable');
        }
        $owned = CourseEnrollment::where('user_id', $user->id)->whereIn('course_id', $courseIds)->whereIn('status', ['active', 'completed', 'suspended'])->exists();
        if ($owned) {
            throw new DomainException('You are already enrolled in one of these courses.', 'already_enrolled', 409);
        }
        $lines = [];
        $currency = null;
        foreach ($courses as $c) {
            $p = $this->pricing->effective($c);
            if ($p['is_free']) {
                throw new DomainException('Free courses are enrolled directly, not purchased.', 'free_course_in_cart');
            }
            if ($currency !== null && $currency !== $p['currency']) {
                throw new DomainException('Courses in different currencies cannot be bought together.', 'mixed_currency');
            }
            $currency = $p['currency'];
            $lines[$c->id] = $p['amount_minor'];
        }
        $discounts = [];
        $coupon = null;
        if ($couponCode) {
            $q = $this->coupons->quote($couponCode, $user, $lines, $lock);
            $coupon = $q['coupon'];
            $discounts = $q['per_course'];
        }
        $subtotal = array_sum($lines);
        $discount = array_sum($discounts);

        return ['courses' => $courses, 'lines' => $lines, 'discounts' => $discounts, 'coupon' => $coupon, 'currency' => $currency,
            'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'total_minor' => $subtotal - $discount];
    }

    /** Creates (or reuses) a pending order, then initialises payment with the gateway. */
    public function begin(User $user, array $courseIds, ?string $couponCode): array
    {
        $order = DB::transaction(function () use ($user, $courseIds, $couponCode) {
            // Serialise a user's concurrent checkouts so double-clicks cannot create duplicate orders.
            User::whereKey($user->id)->lockForUpdate()->first();
            $quote = $this->quote($user, $courseIds, $couponCode, lock: true);

            $existing = Order::where('user_id', $user->id)->where('status', 'pending')->where('expires_at', '>', now())
                ->where('total_minor', $quote['total_minor'])->with('items')->get()
                ->first(fn ($o) => $o->items->pluck('course_id')->sort()->values()->all() === collect($courseIds)->unique()->sort()->values()->all());
            if ($existing) {
                return $existing;
            }
            $order = Order::create([
                'number' => $this->orderNumber(), 'user_id' => $user->id, 'status' => 'pending', 'currency' => $quote['currency'],
                'subtotal_minor' => $quote['subtotal_minor'], 'discount_minor' => $quote['discount_minor'], 'total_minor' => $quote['total_minor'],
                'coupon_id' => $quote['coupon']?->id, 'expires_at' => now()->addHours(2),
            ]);
            foreach ($quote['courses'] as $c) {
                $disc = $quote['discounts'][$c->id] ?? 0;
                OrderItem::create(['order_id' => $order->id, 'course_id' => $c->id, 'title' => $c->title, 'unit_price_minor' => $quote['lines'][$c->id],
                    'discount_minor' => $disc, 'total_minor' => $quote['lines'][$c->id] - $disc]);
            }
            if ($quote['coupon']) {
                $this->coupons->reserve($quote['coupon'], $user, $order->id, $quote['discount_minor']);
            }
            $this->audit->log('order.created', $order, new: ['total_minor' => $order->total_minor, 'currency' => $order->currency], actorId: $user->id);

            return $order;
        });

        if ($order->total_minor === 0) {
            $payment = $this->payments->settleFree($order);

            return ['order' => $order->fresh('items'), 'payment' => $payment, 'authorization_url' => null];
        }
        $payment = $this->payments->initialize($order->fresh(), $user);

        return ['order' => $order->fresh('items'), 'payment' => $payment, 'authorization_url' => $payment->authorization_url];
    }

    private function orderNumber(): string
    {
        do {
            $n = 'GL-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Order::where('number', $n)->exists());

        return $n;
    }
}
