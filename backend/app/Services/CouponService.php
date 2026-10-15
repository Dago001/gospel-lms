<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\User;

class CouponService
{
    /**
     * @param  array<string,int>  $lines  course_id => payable amount (minor units)
     * @return array{coupon:Coupon, total:int, per_course:array<string,int>}
     */
    public function quote(string $code, User $user, array $lines, bool $lock = false): array
    {
        $q = Coupon::whereRaw('lower(code) = ?', [mb_strtolower(trim($code))]);
        $coupon = ($lock ? $q->lockForUpdate() : $q)->first();
        $now = now();
        if (! $coupon || ! $coupon->active || ($coupon->starts_at && $coupon->starts_at->gt($now)) || ($coupon->ends_at && $coupon->ends_at->lt($now))) {
            throw new DomainException('This coupon code is not valid.', 'invalid_coupon');
        }
        if ($coupon->max_redemptions !== null && $coupon->redemptions()->whereIn('status', ['reserved', 'redeemed'])->count() >= $coupon->max_redemptions) {
            throw new DomainException('This coupon has been fully redeemed.', 'coupon_exhausted');
        }
        if ($coupon->redemptions()->where('user_id', $user->id)->whereIn('status', ['reserved', 'redeemed'])->count() >= $coupon->per_user_limit) {
            throw new DomainException('You have already used this coupon.', 'coupon_limit_reached');
        }
        $eligible = array_filter($lines, fn ($_, $id) => $coupon->course_ids === null || in_array($id, $coupon->course_ids, true), ARRAY_FILTER_USE_BOTH);
        $base = array_sum($eligible);
        if ($base <= 0) {
            throw new DomainException('This coupon does not apply to the selected courses.', 'coupon_not_applicable');
        }
        $discount = $coupon->type === 'percent' ? intdiv($base * $coupon->value, 100) : min($coupon->value, $base);

        // Allocate proportionally; the remainder goes to the last eligible line so the sum is exact.
        $per = [];
        $left = $discount;
        $ids = array_keys($eligible);
        foreach ($ids as $i => $id) {
            $share = $i === count($ids) - 1 ? $left : intdiv($discount * $eligible[$id], $base);
            $per[$id] = min($share, $eligible[$id]);
            $left -= $per[$id];
        }

        return ['coupon' => $coupon, 'total' => array_sum($per), 'per_course' => $per];
    }

    public function reserve(Coupon $coupon, User $user, string $orderId, int $discount): CouponRedemption
    {
        return CouponRedemption::create(['coupon_id' => $coupon->id, 'user_id' => $user->id, 'order_id' => $orderId, 'discount_minor' => $discount, 'status' => 'reserved']);
    }

    public function release(string $orderId): void
    {
        CouponRedemption::where('order_id', $orderId)->where('status', 'reserved')->update(['status' => 'released']);
    }

    public function confirm(string $orderId): void
    {
        CouponRedemption::where('order_id', $orderId)->where('status', 'reserved')->update(['status' => 'redeemed']);
    }
}
