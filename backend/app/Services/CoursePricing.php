<?php

namespace App\Services;

use App\Models\Course;
use Carbon\CarbonInterface;

/** Single source of truth for what a student pays. The frontend never supplies a price. */
class CoursePricing
{
    /** @return array{amount_minor:int, list_minor:int, currency:string, discount_active:bool, is_free:bool} */
    public function effective(Course $course, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $discount = $course->discount_price_minor !== null
            && (! $course->discount_starts_at || $course->discount_starts_at->lte($at))
            && (! $course->discount_ends_at || $course->discount_ends_at->gte($at));
        $amount = $discount ? $course->discount_price_minor : $course->price_minor;

        return ['amount_minor' => $amount, 'list_minor' => $course->price_minor, 'currency' => $course->currency,
            'discount_active' => $discount, 'is_free' => $amount === 0];
    }
}
